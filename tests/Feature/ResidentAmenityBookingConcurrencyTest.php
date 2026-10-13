<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class ResidentAmenityBookingConcurrencyTest extends TestCase
{
    use ResidentAmenityBookingFixtures;

    /** @var array<int, string> */
    private array $extraAmenityIds = [];

    /** @var array<int, string> */
    private array $eventIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('TEST_HTTP_BASE_URL')) {
            $this->markTestSkipped('Set TEST_HTTP_BASE_URL to a multi-worker test server using the same dedicated database.');
        }
        $this->createBookingFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->amenityId)) {
            $amenityIds = [$this->amenityId, ...$this->extraAmenityIds];
            foreach ($amenityIds as $amenityId) {
                DB::table('user_in_app_notifications')->where('category', 'AMENITY_BOOKING')->where('deep_link_url', 'like', '%'.$amenityId.'%')->delete();
            }
            $bookingIds = DB::table('amenity_bookings')->whereIn('amenity_id', $amenityIds)->pluck('id');
            $paymentIds = DB::table('amenity_booking_payments')->whereIn('booking_id', $bookingIds)->pluck('id');
            DB::table('audit_logs')->whereIn('record_id', $paymentIds)->delete();
            DB::table('audit_logs')->whereIn('id', $this->eventIds)->delete();
            DB::table('amenity_booking_payments')->whereIn('booking_id', $bookingIds)->delete();
            DB::table('amenity_bookings')->whereIn('amenity_id', $amenityIds)->delete();
            DB::table('amenity_time_slots')->whereIn('amenity_id', $amenityIds)->delete();
            DB::table('amenities')->whereIn('id', $amenityIds)->delete();
            DB::table('amenity_categories')->where('id', $this->categoryId)->delete();
            $userIds = [$this->residentUser->id, $this->secondUser->id, $this->adminUser->id];
            DB::table('user_in_app_notifications')->whereIn('recipient_user_id', $userIds)->delete();
            DB::table('audit_logs')->whereIn('performed_by_user_id', $userIds)->delete();
            foreach (['residents', 'user_sessions', 'user_roles'] as $table) {
                DB::table($table)->whereIn('user_id', $userIds)->delete();
            }
            DB::table('apartments')->where('block_id', $this->blockId)->delete();
            DB::table('floors')->where('block_id', $this->blockId)->delete();
            DB::table('blocks')->where('id', $this->blockId)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
        }
        parent::tearDown();
    }

    /** @param array<int, string> $tokens
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<int, string>>  $headers
     * @param  array<int, string>  $paths
     * @return array<int, int>
     */
    private function parallelRequests(array $tokens, array $payload, string $path = '/api/v1/resident/amenity-bookings', ?\Closure $beforeUnlock = null, string $method = 'POST', array $headers = [], array $paths = []): array
    {
        $multi = curl_multi_init();
        $handles = [];
        DB::beginTransaction();
        DB::table('amenities')->where('id', $this->amenityId)->lockForUpdate()->first();
        try {
            foreach ($tokens as $index => $token) {
                $handle = curl_init(rtrim(getenv('TEST_HTTP_BASE_URL'), '/').($paths[$index] ?? $path));
                curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => json_encode($payload[$index] ?? $payload), CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Accept: application/json', 'Content-Type: application/json', ...($headers[$index] ?? [])], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
                curl_multi_add_handle($multi, $handle);
                $handles[] = $handle;
            }
            curl_multi_exec($multi, $running);
            usleep(200000);
            curl_multi_exec($multi, $running);
            if ($beforeUnlock) {
                $beforeUnlock();
            }
            DB::commit();
            do {
                curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 0.1);
                }
            } while ($running);
            $statuses = [];
            foreach ($handles as $handle) {
                $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
                $this->assertContains($status, [200, 201, 409, 422], curl_error($handle).' '.curl_multi_getcontent($handle));
                $statuses[] = $status;
            }
            sort($statuses);

            return $statuses;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    }

    public function test_two_residents_compete_for_last_booking_without_overbooking(): void
    {
        DB::table('amenity_time_slots')->where('id', $this->slotId)->update(['max_bookings' => 1]);
        $this->assertSame([201, 409], $this->parallelRequests([$this->residentToken, $this->secondToken], $this->bookingPayload()));
        $this->assertSame(1, DB::table('amenity_bookings')->where('amenity_id', $this->amenityId)->count());
    }

    public function test_multiple_bookings_are_allowed_up_to_both_limits(): void
    {
        $this->assertSame([201, 201], $this->parallelRequests([$this->residentToken, $this->secondToken], $this->bookingPayload()));
        $this->assertSame(4, (int) DB::table('amenity_bookings')->where('amenity_id', $this->amenityId)->sum('attendee_count'));
    }

    public function test_total_attendees_are_enforced_under_contention(): void
    {
        $this->assertSame([201, 409], $this->parallelRequests([$this->residentToken, $this->secondToken], $this->bookingPayload(['attendee_count' => 3])));
    }

    public function test_current_slot_configuration_is_read_after_waiting_for_lock(): void
    {
        $statuses = $this->parallelRequests([$this->residentToken], $this->bookingPayload(), beforeUnlock: function (): void {
            DB::table('amenity_time_slots')->where('id', $this->slotId)->update(['is_active' => 0]);
        });
        $this->assertSame([409], $statuses);
    }

    public function test_closure_rechecks_bookings_committed_while_waiting_for_amenity_lock(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $url = '/api/v1/admin/amenities/'.$this->amenityId;
        $token = $this->getJson($url.'/closure-impact')->assertOk()->json('confirmation_token');
        $payload = ['is_active' => false, 'updated_at' => $this->getJson($url)->json('updated_at'), 'booking_action' => 'cancel', 'reason' => 'Đóng cửa đột xuất', 'confirmation_token' => $token];
        $statuses = $this->parallelRequests([$this->adminToken], $payload, $url.'/status', function () use ($id): void {
            $copy = (array) DB::table('amenity_bookings')->where('id', $id)->first();
            $copy['id'] = (string) Str::uuid();
            $copy['booking_code'] = 'BK-'.Str::random(12);
            $copy['checkin_qr_code'] = 'QR-'.Str::random(32);
            $copy['start_time'] = '13:00';
            $copy['end_time'] = '14:00';
            DB::table('amenity_bookings')->insert($copy);
        }, 'PATCH');
        $this->assertSame([409], $statuses);
        $this->assertSame(2, DB::table('amenity_bookings')->where('amenity_id', $this->amenityId)->where('status', 'PENDING')->count());
        $this->assertDatabaseHas('amenities', ['id' => $this->amenityId, 'is_active' => 1]);
    }

    public function test_booking_is_blocked_when_pause_commits_while_waiting_for_lock(): void
    {
        $this->assertSame([409], $this->parallelRequests([$this->residentToken], $this->bookingPayload(), beforeUnlock: function (): void {
            DB::table('amenities')->where('id', $this->amenityId)->update(['is_active' => 0]);
        }));
        $this->assertSame(0, DB::table('amenity_bookings')->where('amenity_id', $this->amenityId)->count());
    }

    public function test_concurrent_cancellation_succeeds_only_once(): void
    {
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $this->assertSame([200, 409], $this->parallelRequests([$this->residentToken, $this->residentToken], [], '/api/v1/resident/amenity-bookings/'.$id.'/cancel'));
    }

    public function test_concurrent_admin_updates_accept_only_one_timestamp_token(): void
    {
        $timestamp = DB::table('amenities')->where('id', $this->amenityId)->value('updated_at');
        $this->assertSame([200, 409], $this->parallelRequests([$this->adminToken, $this->adminToken], ['amenity_name' => 'Concurrent winner', 'updated_at' => $timestamp], '/api/v1/admin/amenities/'.$this->amenityId, method: 'PUT'));
        $this->assertSame(1, DB::table('audit_logs')->where('record_id', $this->amenityId)->count());
    }

    public function test_concurrent_payment_confirmations_record_only_one_bank_transaction(): void
    {
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 0]);
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $payloads = array_map(fn (string $reference): array => ['bank_transaction_id' => $reference, 'received_amount' => 200000, 'received_at' => now()->toIso8601String()], ['VCB-RACE-A', 'VCB-RACE-B']);
        $this->assertSame([200, 409], $this->parallelRequests([$this->adminToken, $this->adminToken], $payloads, '/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/payment/confirm'));
        $payment = DB::table('amenity_booking_payments')->where('booking_id', $id)->first();
        $this->assertSame('PAID', $payment->status);
        $this->assertSame(1, DB::table('audit_logs')->where('table_name', 'amenity_booking_payments')->where('record_id', $payment->id)->count());
    }

    public function test_concurrent_payment_confirmation_and_resident_cancellation_keep_receipt_for_refund(): void
    {
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 0]);
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $statuses = $this->parallelRequests([$this->adminToken, $this->residentToken], [
            ['bank_transaction_id' => 'VCB-CONFIRM-CANCEL', 'received_amount' => 200000, 'received_at' => now()->toIso8601String()],
            ['reason' => 'QA concurrent cancellation'],
        ], paths: ['/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/payment/confirm', '/api/v1/resident/amenity-bookings/'.$id.'/cancel']);
        $this->assertSame([200, 200], $statuses);
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'REVIEW', 'bank_transaction_id' => 'VCB-CONFIRM-CANCEL', 'received_amount' => 200000, 'refund_required' => 1]);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->getJson($this->availabilityUrl())->assertOk()->assertJsonPath('slots.0.remaining_attendees', 4);
    }

    public function test_concurrent_receipt_aliases_across_amenities_credit_only_one_booking(): void
    {
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 0]);
        $first = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $amenity = (array) DB::table('amenities')->where('id', $this->amenityId)->first();
        $amenity['id'] = (string) Str::uuid();
        $amenity['amenity_code'] = 'TEST-'.Str::random(12);
        $this->extraAmenityIds[] = $amenity['id'];
        DB::table('amenities')->insert($amenity);
        $slot = (array) DB::table('amenity_time_slots')->where('id', $this->slotId)->first();
        $slot['id'] = (string) Str::uuid();
        $slot['amenity_id'] = $amenity['id'];
        DB::table('amenity_time_slots')->insert($slot);
        $second = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload(['amenity_id' => $amenity['id'], 'slot_id' => $slot['id']]))->assertCreated()->json('booking.id');
        $reference = 'RACE-'.Str::uuid();
        $payloads = array_map(fn (string $value): array => ['bank_transaction_id' => $value, 'received_amount' => 200000, 'received_at' => now()->toIso8601String()], [$reference, 'PG-'.$reference]);
        $paths = ['/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$first.'/payment/confirm', '/api/v1/admin/amenities/'.$amenity['id'].'/bookings/'.$second.'/payment/confirm'];
        $statuses = $this->parallelRequests([$this->adminToken, $this->adminToken], $payloads, paths: $paths);
        $this->assertSame([200, 422], $statuses);
        $this->assertSame(1, DB::table('amenity_booking_payments')->whereIn('booking_id', [$first, $second])->where('status', 'PAID')->count());
    }

    public function test_concurrent_void_and_paid_ipns_never_leave_booking_paid(): void
    {
        if (! getenv('TEST_SEPAY_IPN_SECRET')) {
            $this->markTestSkipped('Set TEST_SEPAY_IPN_SECRET and configure the QA server checkout with the same credentials.');
        }
        config(['amenity_payments.checkout_environment' => 'sandbox', 'amenity_payments.checkout_merchant_id' => 'SP-TEST-QA',
            'amenity_payments.checkout_secret' => 'qa-checkout-secret', 'amenity_payments.checkout_ipn_secret' => getenv('TEST_SEPAY_IPN_SECRET')]);
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 0]);
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $invoice = $this->postJson('/api/v1/resident/amenity-bookings/'.$id.'/payment/checkout')->assertOk()->json('fields.order_invoice_number');
        $transaction = (string) Str::uuid();
        $paid = ['timestamp' => now()->timestamp, 'notification_type' => 'ORDER_PAID',
            'order' => ['id' => 'qa-order', 'order_invoice_number' => $invoice, 'order_status' => 'CAPTURED', 'order_currency' => 'VND', 'order_amount' => '200000'],
            'transaction' => ['id' => $transaction, 'transaction_id' => 'RACE-'.$transaction, 'payment_method' => 'BANK_TRANSFER', 'transaction_type' => 'PAYMENT',
                'transaction_status' => 'APPROVED', 'transaction_currency' => 'VND', 'transaction_amount' => '200000', 'transaction_date' => now()->format('Y-m-d H:i:s')]];
        $void = $paid;
        $void['notification_type'] = 'TRANSACTION_VOID';
        $void['transaction']['transaction_status'] = 'VOID';
        foreach (['ORDER_PAID', 'TRANSACTION_VOID'] as $type) {
            $this->eventIds[] = (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'sepay-ipn:sandbox:'.$transaction.':'.$type);
        }
        $header = ['X-Secret-Key: '.getenv('TEST_SEPAY_IPN_SECRET')];
        $this->assertSame([200, 200], $this->parallelRequests(['', ''], [$paid, $void], '/api/v1/amenity-payments/sepay/ipn', headers: [$header, $header]));
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'is_paid' => 0, 'status' => 'CANCELLED']);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'REVIEW']);
    }

    public function test_payment_confirmation_after_waiting_for_expiry_never_restores_capacity(): void
    {
        DB::table('amenities')->where('id', $this->amenityId)->update(['requires_admin_approval' => 0]);
        $id = $this->postJson('/api/v1/resident/amenity-bookings', $this->bookingPayload())->assertCreated()->json('booking.id');
        $this->assertSame([200], $this->parallelRequests([$this->adminToken], ['bank_transaction_id' => 'VCB-LATE', 'received_amount' => 200000, 'received_at' => now()->toIso8601String()], '/api/v1/admin/amenities/'.$this->amenityId.'/bookings/'.$id.'/payment/confirm', function () use ($id): void {
            DB::table('amenity_booking_payments')->where('booking_id', $id)->update(['expires_at' => now()->subSecond()]);
        }));
        $this->assertDatabaseHas('amenity_bookings', ['id' => $id, 'status' => 'CANCELLED', 'is_paid' => 0]);
        $this->assertDatabaseHas('amenity_booking_payments', ['booking_id' => $id, 'status' => 'REVIEW', 'refund_required' => 1]);
    }
}
