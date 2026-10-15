<?php

namespace App\Jobs;

use App\Mail\ParcelReceivedMail;
use App\Models\Parcel;
use App\Models\ParcelNotificationLog;
use App\Models\Resident;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\WebPushService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendParcelNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Số lần thử lại tối đa cho job
     */
    public int $tries = 3;

    /**
     * Thời gian chờ giữa các lần thử lại (giây)
     */
    public int $backoff = 30;

    public function __construct(
        public string $parcelId
    ) {}

    public function handle(WebPushService $pushService): void
    {
        // 1. Tải bưu phẩm cùng các quan hệ tối thiểu cần thiết (tối ưu database query)
        $parcel = Parcel::with([
            'apartment:id,apartment_number,block_id',
            'apartment.block:id,block_name,block_code',
            'recipientUser:id,full_name,email,phone_number',
        ])->find($this->parcelId);

        if (! $parcel) {
            Log::warning("SendParcelNotificationJob: Không tìm thấy bưu phẩm {$this->parcelId}. Bỏ qua.");

            return;
        }

        // Chỉ gửi thông báo tiếp nhận cho các trạng thái tiếp nhận thành công
        if (! in_array($parcel->status, ['RECEIVED_AT_RECEPTION', 'RECEIVED'])) {
            Log::info("SendParcelNotificationJob: Bưu phẩm {$parcel->id} có trạng thái {$parcel->status}, không phải tiếp nhận mới. Bỏ qua.");

            return;
        }

        // 2. Xác định chính xác cư dân nhận bưu phẩm từ quan hệ database
        $user = $this->resolveRecipientUser($parcel);
        $recipientName = $parcel->recipient_name ?: ($user?->full_name ?? 'Quý cư dân');
        $recipientEmail = $user?->email;

        // Lưu thông báo nội bộ trong app cư dân (User In-App Notifications) nếu có user
        if ($user) {
            $this->createInAppNotification($user, $parcel);
        }

        $emailException = null;
        $pushException = null;

        // 3. KÊNH 1: GỬI EMAIL THÔNG BÁO TIẾP NHẬN BƯU PHẨM (Độc lập)
        try {
            $this->processEmailChannel($parcel, $user, $recipientName, $recipientEmail);
        } catch (Throwable $e) {
            $emailException = $e;
            Log::error("SendParcelNotificationJob: Lỗi gửi email cho bưu phẩm {$parcel->id}: {$e->getMessage()}");
        }

        // 4. KÊNH 2: GỬI WEB PUSH NOTIFICATION (Độc lập, không bị lỗi email làm dừng)
        try {
            $this->processWebPushChannel($pushService, $parcel, $user);
        } catch (Throwable $e) {
            $pushException = $e;
            Log::error("SendParcelNotificationJob: Lỗi gửi Web Push cho bưu phẩm {$parcel->id}: {$e->getMessage()}");
        }

        // 5. Nếu có kênh lỗi và vẫn còn lượt retry, ném exception để queue retry kênh còn lại an toàn
        if ($emailException !== null || $pushException !== null) {
            $errorDetails = [];
            if ($emailException) {
                $errorDetails[] = 'Email: '.$emailException->getMessage();
            }
            if ($pushException) {
                $errorDetails[] = 'Push: '.$pushException->getMessage();
            }

            throw new RuntimeException('Thất bại một phần khi gửi thông báo: '.implode(' | ', $errorDetails));
        }
    }

    /**
     * Xác định tài khoản cư dân nhận bưu phẩm theo thứ tự ưu tiên
     */
    protected function resolveRecipientUser(Parcel $parcel): ?User
    {
        // Ưu tiên 1: Đã liên kết trực tiếp recipient_user_id
        if ($parcel->recipientUser) {
            return $parcel->recipientUser;
        }

        // Ưu tiên 2: Tìm cư dân trong căn hộ trùng khớp số điện thoại
        if (! empty($parcel->recipient_phone) && ! empty($parcel->apartment_id)) {
            $phone = trim($parcel->recipient_phone);
            $resident = Resident::where('apartment_id', $parcel->apartment_id)
                ->where('is_active', true)
                ->where(function ($q) use ($phone) {
                    $q->where('phone_number', $phone)
                        ->orWhereHas('user', fn ($uq) => $uq->where('phone_number', $phone));
                })
                ->with('user:id,full_name,email,phone_number')
                ->first();

            if ($resident?->user) {
                return $resident->user;
            }
        }

        // Ưu tiên 3: Chủ hộ đang hoạt động của căn hộ
        if ($parcel->apartment?->headOfHousehold?->user) {
            return $parcel->apartment->headOfHousehold->user;
        }

        // Ưu tiên 4: Đại diện cư dân hiện tại của căn hộ
        if ($parcel->apartment?->currentResident) {
            return $parcel->apartment->currentResident;
        }

        return null;
    }

    /**
     * Xử lý gửi email với cơ chế chống gửi trùng (Idempotency)
     */
    protected function processEmailChannel(Parcel $parcel, ?User $user, string $recipientName, ?string $recipientEmail): void
    {
        $destination = $recipientEmail ?: 'NO_EMAIL';

        // Kiểm tra xem email đã được gửi thành công trước đó hay chưa
        $existingLog = ParcelNotificationLog::where('parcel_id', $parcel->id)
            ->where('channel', 'EMAIL')
            ->first();

        if ($existingLog && $existingLog->status === 'SENT') {
            Log::info("SendParcelNotificationJob: Email cho bưu phẩm {$parcel->id} đã gửi thành công trước đó. Bỏ qua để chống trùng.");

            return;
        }

        if (empty($recipientEmail) || ! filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            ParcelNotificationLog::updateOrCreate(
                ['parcel_id' => $parcel->id, 'channel' => 'EMAIL'],
                [
                    'recipient_user_id' => $user?->id,
                    'destination' => $destination,
                    'status' => 'FAILED',
                    'error_message' => 'Cư dân nhận bưu phẩm không có địa chỉ email hợp lệ',
                    'sent_at' => null,
                ]
            );

            return;
        }

        $log = ParcelNotificationLog::updateOrCreate(
            ['parcel_id' => $parcel->id, 'channel' => 'EMAIL'],
            [
                'recipient_user_id' => $user?->id,
                'destination' => $recipientEmail,
                'status' => 'PENDING',
            ]
        );

        try {
            $locationName = match ($parcel->stored_location_type) {
                'SMART_LOCKER' => 'Tủ đồ thông minh Smart Locker',
                'SECURITY_BOOTH' => 'Chốt trực An ninh Tòa nhà',
                default => 'Quầy Lễ tân Tòa nhà',
            };

            Mail::to($recipientEmail)->send(
                new ParcelReceivedMail(
                    parcel: $parcel,
                    recipientName: $recipientName,
                    pickupLocation: $locationName,
                    pinCode: $parcel->pickup_pin_code
                )
            );

            $log->update([
                'status' => 'SENT',
                'sent_at' => Carbon::now(),
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $log->update([
                'status' => 'FAILED',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Xử lý gửi Web Push Notification với cơ chế chống gửi trùng (Idempotency)
     */
    protected function processWebPushChannel(WebPushService $pushService, Parcel $parcel, ?User $user): void
    {
        // Kiểm tra xem push đã được gửi thành công trước đó hay chưa
        $existingLog = ParcelNotificationLog::where('parcel_id', $parcel->id)
            ->where('channel', 'WEB_PUSH')
            ->first();

        if ($existingLog && $existingLog->status === 'SENT') {
            Log::info("SendParcelNotificationJob: Web Push cho bưu phẩm {$parcel->id} đã gửi thành công trước đó. Bỏ qua để chống trùng.");

            return;
        }

        if (! $user) {
            ParcelNotificationLog::updateOrCreate(
                ['parcel_id' => $parcel->id, 'channel' => 'WEB_PUSH'],
                [
                    'recipient_user_id' => null,
                    'destination' => 'NO_USER',
                    'status' => 'FAILED',
                    'error_message' => 'Không tìm thấy tài khoản người dùng tương ứng để gửi Web Push',
                    'sent_at' => null,
                ]
            );

            return;
        }

        $activeSubscriptions = WebPushSubscription::where('user_id', $user->id)
            ->active()
            ->get();

        if ($activeSubscriptions->isEmpty()) {
            ParcelNotificationLog::updateOrCreate(
                ['parcel_id' => $parcel->id, 'channel' => 'WEB_PUSH'],
                [
                    'recipient_user_id' => $user->id,
                    'destination' => 'NO_SUBSCRIPTION',
                    'status' => 'FAILED',
                    'error_message' => 'Cư dân chưa đăng ký thiết bị nhận Web Push Notification',
                    'sent_at' => null,
                ]
            );

            return;
        }

        $log = ParcelNotificationLog::updateOrCreate(
            ['parcel_id' => $parcel->id, 'channel' => 'WEB_PUSH'],
            [
                'recipient_user_id' => $user->id,
                'destination' => 'USER:'.$user->id,
                'status' => 'PENDING',
            ]
        );

        $payload = [
            'title' => 'Bạn có bưu phẩm mới',
            'body' => "Lễ tân đã tiếp nhận kiện hàng ({$parcel->tracking_number}) dành cho căn hộ của bạn. Vui lòng kiểm tra thông tin.",
            'icon' => '/favicon.ico',
            'badge' => '/favicon.ico',
            'data' => [
                'parcel_id' => $parcel->id,
                'tracking_number' => $parcel->tracking_number,
                'apartment_number' => $parcel->apartment?->apartment_number,
                'url' => '/cu-dan?tab=overview',
            ],
        ];

        $result = $pushService->sendToUser($user, $payload);

        if ($result['sent_count'] > 0) {
            $log->update([
                'status' => 'SENT',
                'sent_at' => Carbon::now(),
                'error_message' => null,
            ]);
        } else {
            $log->update([
                'status' => 'FAILED',
                'error_message' => 'Không thể gửi đến bất kỳ subscription nào của người dùng',
            ]);

            throw new RuntimeException("Tất cả {$result['failed_count']} subscription Web Push của cư dân đều trả về lỗi");
        }
    }

    /**
     * Tạo thông báo hộp thư nội bộ (User In-App Notifications)
     */
    protected function createInAppNotification(User $user, Parcel $parcel): void
    {
        try {
            $deepLink = "app://parcels/{$parcel->id}";
            $exists = DB::table('user_in_app_notifications')
                ->where('recipient_user_id', $user->id)
                ->where('deep_link_url', $deepLink)
                ->exists();

            if (! $exists) {
                DB::table('user_in_app_notifications')->insert([
                    'id' => (string) Str::uuid(),
                    'recipient_user_id' => $user->id,
                    'title' => 'Kiện hàng mới đã được tiếp nhận',
                    'body_message' => "Lễ tân đã tiếp nhận kiện hàng ({$parcel->tracking_number}) từ {$parcel->courier_company}. Quý cư dân vui lòng đến quầy nhận hàng.",
                    'deep_link_url' => $deepLink,
                    'category' => 'PARCEL',
                    'is_read' => 0,
                    'created_at' => Carbon::now(),
                ]);
            }
        } catch (Throwable $e) {
            Log::notice("Không thể tạo in-app notification cho bưu phẩm {$parcel->id}: {$e->getMessage()}");
        }
    }
}
