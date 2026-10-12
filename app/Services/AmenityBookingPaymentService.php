<?php

namespace App\Services;

use App\Repositories\Eloquent\DatabaseResidentAmenityBookingRepository;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class AmenityBookingPaymentService
{
    /** @param array<string, mixed> $data */
    public function webhook(Request $request, array $data): void
    {
        // SePay's signed dashboard test uses id=0 and must never credit a booking.
        if ((int) $data['id'] === 0) {
            return;
        }
        $eventId = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'sepay:amenity:'.$data['id']);
        try {
            DB::transaction(function () use ($request, $data, $eventId): void {
                if (DB::table('audit_logs')->where('id', $eventId)->exists()) {
                    return;
                }
                DB::table('audit_logs')->insert([
                    'id' => $eventId, 'table_name' => 'amenity_payment_webhooks', 'record_id' => $eventId,
                    'action' => 'INSERT', 'client_ip_address' => $request->ip(),
                    'new_data' => json_encode($data, JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
                if ($data['transferType'] !== 'in') {
                    return;
                }
                preg_match_all('/(?<![A-Z0-9])TI[A-Z0-9]{18}(?![A-Z0-9])/i', $data['content'].' '.($data['code'] ?? ''), $matches);
                $references = array_unique(array_map('strtoupper', $matches[0]));
                $payment = count($references) === 1 ? DB::table('amenity_booking_payments')->where('reference', reset($references))->first() : null;
                if (! $payment || $payment->account_number !== $data['accountNumber'] || strcasecmp($payment->bank_name, $data['gateway']) !== 0) {
                    app(ResidentAmenityBookingService::class)->notifyManagers('', '', 'Giao dịch SePay cần đối soát', 'Giao dịch SePay '.$data['id'].' không khớp duy nhất mã đăng ký và tài khoản nhận. Kiểm tra giao dịch trong SePay trước khi xác nhận thủ công.');

                    return;
                }
                $booking = DB::table('amenity_bookings')->where('id', $payment->booking_id)->first();
                if (! $booking || $booking->deleted_at) {
                    app(ResidentAmenityBookingService::class)->notifyManagers('', '', 'Giao dịch SePay cần đối soát', 'Giao dịch SePay '.$data['id'].' thuộc đăng ký không còn truy cập được. Kiểm tra trong SePay.');

                    return;
                }
                try {
                    $this->decide($request, $booking->amenity_id, $booking->id, [
                        'bank_transaction_id' => ($data['referenceCode'] ?? '') ?: 'SEPAY-'.$data['id'],
                        'received_amount' => $data['transferAmount'],
                        'received_at' => $data['transactionDate'],
                    ], automatic: true);
                } catch (ValidationException $exception) {
                    if (! isset($exception->errors()['bank_transaction_id'])) {
                        throw $exception;
                    }
                    app(ResidentAmenityBookingService::class)->notifyManagers($booking->amenity_id, $booking->booking_code, 'Giao dịch trùng cần đối soát', 'Giao dịch SePay '.$data['id'].' có mã ngân hàng đã dùng cho đăng ký khác. Không tự xác nhận.');
                }
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! DB::table('audit_logs')->where('id', $eventId)->exists()) {
                throw $exception;
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function format(?object $payment, ?object $booking = null): ?array
    {
        if (! $payment) {
            return null;
        }
        $expiry = $payment->status === 'REPORTED' && $booking ? Carbon::parse($booking->booking_date.' '.$booking->start_time) : ($payment->expires_at ? Carbon::parse($payment->expires_at) : null);
        $reviewDue = $payment->reported_at ? Carbon::parse($payment->reported_at)->addMinutes(max(1, (int) config('amenity_payments.review_minutes'))) : null;
        $expired = in_array($payment->status, ['PENDING', 'REPORTED'], true) && $expiry && $expiry->lte(now());
        $status = $expired ? 'EXPIRED' : $payment->status;
        $canPay = $status === 'PENDING' && (! $booking || in_array($booking->status, ['APPROVED', 'CONFIRMED'], true));
        $query = http_build_query(['amount' => (int) $payment->amount, 'addInfo' => $payment->reference, 'accountName' => $payment->account_name], '', '&', PHP_QUERY_RFC3986);

        return array_merge((array) $payment, [
            'amount' => (int) $payment->amount, 'received_amount' => $payment->received_amount === null ? null : (int) $payment->received_amount,
            'status' => $status, 'can_pay' => $canPay, 'can_confirm' => $status !== 'WAITING_APPROVAL' && ! $payment->bank_transaction_id,
            'checkout_available' => $canPay && AmenitySePayCheckoutService::available(),
            'checkout_environment' => config('amenity_payments.checkout_environment'),
            'refund_required' => (bool) $payment->refund_required,
            'expires_at' => $expiry?->toIso8601String(), 'review_due_at' => $reviewDue?->toIso8601String(),
            'review_overdue' => $status === 'REPORTED' && $reviewDue && $reviewDue->lte(now()),
            'received_at' => $payment->received_at ? Carbon::parse($payment->received_at)->toIso8601String() : null,
            'confirmed_at' => $payment->confirmed_at ? Carbon::parse($payment->confirmed_at)->toIso8601String() : null,
            'qr_url' => $canPay ? 'https://img.vietqr.io/image/'.rawurlencode($payment->bank_bin).'-'.rawurlencode($payment->account_number).'-compact2.png?'.$query : null,
        ]);
    }

    public static function hasWholeVndTotal(float $fee, float $deposit): bool
    {
        $total = round($fee + $deposit, 2);

        return $total === (float) (int) $total;
    }

    /** @param array<string, mixed> $booking */
    public function initialize(array $booking): void
    {
        if ($booking['is_paid']) {
            return;
        }
        $total = round((float) $booking['total_amount'] + (float) $booking['deposit_amount'], 2);
        $amount = (int) $total;
        if (! self::hasWholeVndTotal((float) $booking['total_amount'], (float) $booking['deposit_amount'])) {
            throw ValidationException::withMessages(['amenity_id' => 'Phí và cọc thanh toán QR phải có tổng là số đồng nguyên. Vui lòng liên hệ ban quản lý để kiểm tra cấu hình.']);
        }
        DB::table('amenity_booking_payments')->insert([
            'id' => (string) Str::uuid(), 'booking_id' => $booking['id'], 'reference' => 'TI'.Str::upper(Str::random(18)),
            'amount' => $amount,
            'bank_bin' => config('amenity_payments.bank_bin'), 'bank_name' => config('amenity_payments.bank_name'),
            'account_number' => config('amenity_payments.account_number'), 'account_name' => config('amenity_payments.account_name'),
            'status' => $booking['status'] === 'PENDING' ? 'WAITING_APPROVAL' : 'PENDING',
            'expires_at' => $booking['status'] === 'PENDING' ? null : $this->deadline((object) $booking, 'payment_minutes'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function deadline(object $booking, string $key): Carbon
    {
        return now()->addMinutes(max(1, (int) config('amenity_payments.'.$key)))
            ->min(Carbon::parse($booking->booking_date.' '.$booking->start_time, config('app.timezone')));
    }

    public static function holdingQuery(Builder $query): Builder
    {
        return $query->whereNotExists(function (Builder $payment): void {
            $payment->selectRaw('1')->from('amenity_booking_payments')
                ->whereColumn('amenity_booking_payments.booking_id', 'amenity_bookings.id')
                ->where(function (Builder $blocked): void {
                    $blocked->whereIn('amenity_booking_payments.status', ['EXPIRED', 'CANCELLED', 'REVIEW'])
                        ->orWhere(fn (Builder $due): Builder => $due->where('amenity_booking_payments.status', 'PENDING')->where('amenity_booking_payments.expires_at', '<=', now()))
                        ->orWhere(fn (Builder $due): Builder => $due->where('amenity_booking_payments.status', 'REPORTED')->whereRaw('CONCAT(amenity_bookings.booking_date, " ", amenity_bookings.start_time) <= ?', [now()]));
                });
        });
    }

    /** Must run while the amenity and booking rows are locked. */
    public function synchronize(object $booking, ?Request $request = null): void
    {
        $payment = DB::table('amenity_booking_payments')->where('booking_id', $booking->id)->lockForUpdate()->first();
        if (! $payment) {
            return;
        }
        $changes = [];
        if (in_array($booking->status, ['CANCELLED', 'REJECTED'], true)) {
            $changes = ['status' => $payment->bank_transaction_id || $payment->reported_at ? 'REVIEW' : 'CANCELLED', 'refund_required' => (bool) $payment->bank_transaction_id];
        } elseif ($booking->status === 'APPROVED' && $payment->status === 'WAITING_APPROVAL') {
            $changes = ['status' => 'PENDING', 'expires_at' => $this->deadline($booking, 'payment_minutes')];
        }
        if ($changes && ($changes['status'] !== $payment->status || ($changes['refund_required'] ?? false) !== (bool) $payment->refund_required)) {
            $this->save($payment, $booking, $changes, $request, false);
        }
    }

    public function expire(?string $amenityId = null, ?string $userId = null): int
    {
        $ids = DB::table('amenity_booking_payments')->join('amenity_bookings', 'amenity_bookings.id', '=', 'amenity_booking_payments.booking_id')
            ->where(function (Builder $query): void {
                $query->where(fn (Builder $due): Builder => $due->where('amenity_booking_payments.status', 'PENDING')->where('expires_at', '<=', now()))
                    ->orWhere(fn (Builder $due): Builder => $due->where('amenity_booking_payments.status', 'REPORTED')->whereRaw('CONCAT(amenity_bookings.booking_date, " ", amenity_bookings.start_time) <= ?', [now()]));
            })
            ->when($amenityId, fn (Builder $query): Builder => $query->where('amenity_id', $amenityId))
            ->when($userId, fn (Builder $query): Builder => $query->where('resident_user_id', $userId))->pluck('booking_id');
        foreach ($ids as $id) {
            $this->locked($id, function (object $booking, ?object $payment): void {
                $expiry = $payment?->status === 'REPORTED' ? Carbon::parse($booking->booking_date.' '.$booking->start_time) : Carbon::parse($payment?->expires_at);
                if ($payment && in_array($payment->status, ['PENDING', 'REPORTED'], true) && $expiry->lte(now())) {
                    $this->save($payment, $booking, ['status' => $payment->reported_at ? 'REVIEW' : 'EXPIRED', 'review_reason' => 'Hết thời hạn thanh toán hoặc đối soát.']);
                    if (! $booking->is_paid && in_array($booking->status, ['APPROVED', 'CONFIRMED'], true)) {
                        DB::table('amenity_bookings')->where('id', $booking->id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
                    }
                }
            });
        }

        return $ids->count();
    }

    /** @return array<string, mixed>|null */
    public function resident(Request $request, string $id, bool $report = false): ?array
    {
        $owned = DB::table('amenity_bookings')->where('id', $id)->where('resident_user_id', $request->user()->id)->whereNull('deleted_at')->first();
        abort_unless($owned, 404);
        $this->expire($owned->amenity_id);

        return $this->locked($id, function (object $booking, ?object $payment) use ($request, $report): ?array {
            if (! $payment || ! $report || $payment->status === 'REPORTED') {
                return $this->format($payment, $booking);
            }
            abort_unless($this->format($payment, $booking)['can_pay'], 409, 'Đăng ký chưa được duyệt hoặc thanh toán đã hết hạn.');
            $this->save($payment, $booking, ['status' => 'REPORTED', 'reported_at' => now(), 'expires_at' => Carbon::parse($booking->booking_date.' '.$booking->start_time)], $request);
            app(ResidentAmenityBookingService::class)->notifyManagers($booking->amenity_id, $booking->booking_code,
                'Cư dân báo chuyển khoản · '.$booking->booking_code, 'Cần đối soát phí và tiền cọc cho đăng ký '.$booking->booking_code.'.');

            return $this->format(DB::table('amenity_booking_payments')->where('id', $payment->id)->first(), $booking);
        });
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function decide(Request $request, string $amenityId, string $id, array $data, bool $reject = false, bool $automatic = false): array
    {
        $this->expire($amenityId);
        try {
            return $this->locked($id, function (object $booking, ?object $payment) use ($request, $amenityId, $id, $data, $reject, $automatic): array {
                abort_unless($booking->amenity_id === $amenityId && $payment, 404);
                abort_if(! $automatic && $payment->status === 'WAITING_APPROVAL', 409, 'Phải duyệt đăng ký trước khi đối soát thanh toán.');
                if ($reject) {
                    abort_if($payment->bank_transaction_id, 409, 'Giao dịch đã được ghi nhận, không thể từ chối.');
                    if ($payment->status === 'REVIEW' && $payment->review_reason === $data['reason']) {
                        return $this->format($payment, $booking);
                    }
                    $this->save($payment, $booking, ['status' => 'REVIEW', 'review_reason' => $data['reason']], $request);
                    DB::table('amenity_bookings')->where('id', $id)->whereIn('status', DatabaseResidentAmenityBookingRepository::HOLDING_STATUSES)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
                } else {
                    $reference = Str::upper(trim($data['bank_transaction_id']));
                    $aliases = $this->receiptAliases($reference);
                    if ($payment->bank_transaction_id) {
                        if ($automatic && (! in_array($payment->bank_transaction_id, $aliases, true) || (int) $payment->received_amount !== (int) $data['received_amount'])) {
                            app(ResidentAmenityBookingService::class)->notifyManagers($amenityId, $booking->booking_code, 'Có thêm giao dịch cần đối soát · '.$booking->booking_code, 'Đăng ký đã ghi nhận tiền. Kiểm tra giao dịch bổ sung '.$reference.' trong SePay; hệ thống giữ nguyên giao dịch trước.');

                            return $this->format($payment, $booking);
                        }
                        abort_unless(in_array($payment->bank_transaction_id, $aliases, true) && (int) $payment->received_amount === (int) $data['received_amount'], 409, 'Thanh toán đã được xác nhận bằng giao dịch khác.');

                        return $this->format($payment, $booking);
                    }
                    $amountMatches = (int) $data['received_amount'] === (int) $payment->amount;
                    if (! $automatic && ! $amountMatches) {
                        throw ValidationException::withMessages(['received_amount' => 'Số tiền thực nhận phải bằng tổng phí và cọc. Nếu không khớp, ghi nhận cần xử lý.']);
                    }
                    $receivedAt = Carbon::parse($data['received_at'])->setTimezone(config('app.timezone'));
                    $timeMatches = $receivedAt->gte(Carbon::parse($payment->created_at));
                    if (! $automatic && ! $timeMatches) {
                        throw ValidationException::withMessages(['received_at' => 'Thời điểm nhận tiền không được trước khi tạo yêu cầu thanh toán.']);
                    }
                    if (DB::table('amenity_booking_payments')->where('bank_bin', $payment->bank_bin)->where('id', '!=', $payment->id)
                        ->whereIn('bank_transaction_id', $aliases)->lockForUpdate()->first()) {
                        throw ValidationException::withMessages(['bank_transaction_id' => 'Mã giao dịch đã được sử dụng cho đăng ký khác.']);
                    }
                    // Reserve legacy aliases atomically, including across different amenities.
                    foreach ($aliases as $alias) {
                        DB::table('audit_logs')->insert([
                            'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'amenity-receipt:'.$payment->bank_bin.':'.$alias),
                            'table_name' => 'amenity_payment_receipts', 'record_id' => $payment->id, 'action' => 'INSERT',
                            'performed_by_user_id' => $automatic ? null : $request->user()->id, 'client_ip_address' => $request->ip(),
                            'new_data' => json_encode(['reference' => $reference], JSON_THROW_ON_ERROR), 'created_at' => now(),
                        ]);
                    }
                    $active = $amountMatches && $timeMatches && in_array($booking->status, ['APPROVED', 'CONFIRMED'], true) && in_array($payment->status, ['PENDING', 'REPORTED'], true)
                        && ($payment->status === 'REPORTED' ? Carbon::parse($booking->booking_date.' '.$booking->start_time) : Carbon::parse($payment->expires_at))->gt(now());
                    $this->save($payment, $booking, [
                        'status' => $active ? 'PAID' : 'REVIEW', 'bank_transaction_id' => $reference,
                        'received_amount' => $data['received_amount'], 'received_at' => $receivedAt,
                        'confirmed_by_user_id' => $automatic ? null : $request->user()->id, 'confirmed_at' => now(), 'refund_required' => ! $active,
                        'review_reason' => $active ? null : 'Đã nhận tiền nhưng số tiền, thời điểm hoặc trạng thái đăng ký không hợp lệ. Cần đối soát hoàn tiền.',
                    ], $automatic ? null : $request);
                    if ($active) {
                        DB::table('amenity_bookings')->where('id', $id)->update(['is_paid' => 1, 'updated_at' => now()]);
                    } elseif (in_array($booking->status, ['PENDING', 'APPROVED', 'CONFIRMED'], true)) {
                        DB::table('amenity_bookings')->where('id', $id)->update(['status' => 'CANCELLED', 'updated_at' => now()]);
                    }
                    if ($automatic) {
                        app(ResidentAmenityBookingService::class)->notifyManagers($amenityId, $booking->booking_code, ($active ? 'Đã nhận thanh toán tự động · ' : 'Thanh toán tự động cần đối soát · ').$booking->booking_code, 'Đã nhận giao dịch '.$reference.'.'.($active ? ' Đủ phí và tiền cọc.' : ' Không khôi phục đăng ký; kiểm tra hoàn tiền.'));
                    }
                }

                return $this->format(DB::table('amenity_booking_payments')->where('id', $payment->id)->first(), $booking);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['bank_transaction_id' => 'Mã giao dịch đã được sử dụng cho đăng ký khác.']);
        }
    }

    /** @return array<int, string> */
    private function receiptAliases(string $reference): array
    {
        $aliases = [$reference, 'PG-'.$reference];
        if (str_starts_with($reference, 'PG-')) {
            $aliases[] = substr($reference, 3);
        }

        return array_values(array_unique($aliases));
    }

    public function reviewVoidedTransaction(string $id, string $reference): void
    {
        $this->locked($id, function (object $booking, ?object $payment) use ($reference): void {
            if (! $payment || ($payment->bank_transaction_id && ! in_array($payment->bank_transaction_id, $this->receiptAliases(Str::upper(trim($reference))), true))) {
                return;
            }
            $this->save($payment, $booking, [
                'status' => 'REVIEW', 'review_reason' => 'SePay thông báo hủy giao dịch. Cần kiểm tra sao kê và trạng thái hoàn tiền.',
            ]);
            $changes = ['is_paid' => 0, 'updated_at' => now()];
            if (in_array($booking->status, ['PENDING', 'APPROVED', 'CONFIRMED'], true)) {
                $changes['status'] = 'CANCELLED';
            }
            DB::table('amenity_bookings')->where('id', $booking->id)->update($changes);
        });
    }

    public function locked(string $id, callable $action): mixed
    {
        $existing = DB::table('amenity_bookings')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($existing, 404);

        return DB::transaction(function () use ($existing, $id, $action): mixed {
            DB::table('amenities')->where('id', $existing->amenity_id)->lockForUpdate()->first();
            $booking = DB::table('amenity_bookings')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($booking, 404);
            $payment = DB::table('amenity_booking_payments')->where('booking_id', $id)->lockForUpdate()->first();

            return $action($booking, $payment);
        }, 3);
    }

    /** @param array<string, mixed> $changes */
    private function save(object $payment, object $booking, array $changes, ?Request $request = null, bool $notify = true): void
    {
        DB::table('amenity_booking_payments')->where('id', $payment->id)->update(array_merge($changes, ['updated_at' => now()]));
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'table_name' => 'amenity_booking_payments', 'record_id' => $payment->id, 'action' => 'UPDATE',
            'performed_by_user_id' => $request?->user()?->id, 'client_ip_address' => $request?->ip(),
            'old_data' => json_encode($payment, JSON_THROW_ON_ERROR), 'new_data' => json_encode($changes, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
        if ($notify) {
            app(ResidentAmenityBookingService::class)->notifyResident($booking, 'Cập nhật thanh toán tiện ích', match ($changes['status']) {
                'PAID' => 'Đã xác nhận nhận đủ phí và tiền cọc.',
                'PENDING' => 'Đăng ký đã được duyệt. Vui lòng mở Lịch của tôi để thanh toán QR.',
                'REPORTED' => 'Đã ghi nhận thông báo chuyển khoản. Đang chờ ban quản lý đối soát.',
                'REVIEW' => $changes['review_reason'] ?? 'Đăng ký đã hủy. Liên hệ ban quản lý để đối soát tiền đã chuyển.',
                default => 'Yêu cầu thanh toán đã hủy hoặc hết hạn. Chỗ được giải phóng.',
            });
        }
        app(ResidentAmenityBookingService::class)->invalidateAfterCommit();
    }
}
