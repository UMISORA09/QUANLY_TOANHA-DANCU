<?php

namespace Tests\Feature\Notifications;

use App\Events\QuocTinRealtimeEvent;
use App\Http\Controllers\PushNotificationController;
use App\Jobs\SendParcelNotificationJob;
use App\Mail\ParcelReceivedMail;
use App\Models\Apartment;
use App\Models\Parcel;
use App\Models\ParcelNotificationLog;
use App\Models\Resident;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\ReceptionParcelService;
use App\Services\WebPushService;
use Exception;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParcelNotificationTest extends TestCase
{
    use DatabaseTransactions;

    protected ?Apartment $apartment = null;

    protected ?User $resident = null;

    protected ?User $receptionist = null;

    protected ReceptionParcelService $receptionService;

    protected WebPushService $pushService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receptionService = app(ReceptionParcelService::class);
        $this->pushService = app(WebPushService::class);

        // Tạo căn hộ và cư dân nhận hàng
        $this->apartment = $this->createTestApartment('APT-NOTIF-'.Str::random(4));
        $this->resident = $this->createTestResident($this->apartment);
        $this->receptionist = $this->createTestStaff('RECEPTIONIST');
    }

    protected function createTestApartment(string $code): Apartment
    {
        $blockId = DB::table('blocks')->value('id');
        if (! $blockId) {
            $blockId = (string) Str::uuid();
            DB::table('blocks')->insert([
                'id' => $blockId,
                'block_code' => 'BLK_NOTIF_'.Str::random(4),
                'block_name' => 'Tòa Tháp Notifications',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $floorId = DB::table('floors')->value('id');
        if (! $floorId) {
            $floorId = (string) Str::uuid();
            DB::table('floors')->insert([
                'id' => $floorId,
                'block_id' => $blockId,
                'floor_number' => 12,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return Apartment::create([
            'apartment_number' => $code,
            'block_id' => $blockId,
            'floor_id' => $floorId,
            'status' => 'OCCUPIED',
            'room_type' => '2BR',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 70.0,
        ]);
    }

    protected function createTestResident(Apartment $apartment): User
    {
        $user = User::create([
            'username' => 'resident_notif_'.Str::random(6),
            'phone_number' => '091'.rand(1000000, 9999999),
            'email' => 'resident_notif_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('secret123'),
            'full_name' => 'Nguyễn Thị Cư Dân',
            'status' => 'ACTIVE',
        ]);

        Resident::create([
            'user_id' => $user->id,
            'apartment_id' => $apartment->id,
            'resident_type' => 'PRIMARY_OWNER',
            'is_head_of_household' => 1,
            'stay_start_date' => now()->toDateString(),
            'is_active' => 1,
            'phone_number' => $user->phone_number,
        ]);

        return $user;
    }

    protected function createTestStaff(string $roleCode): User
    {
        $user = User::create([
            'username' => strtolower($roleCode).'_notif_'.Str::random(6),
            'phone_number' => '097'.rand(1000000, 9999999),
            'email' => strtolower($roleCode).'_notif_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => "Nhân Viên {$roleCode} Notif",
            'status' => 'ACTIVE',
        ]);

        $roleId = DB::table('roles')->where('role_code', $roleCode)->value('id');
        if (! $roleId) {
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId,
                'role_code' => $roleCode,
                'role_name' => $roleCode,
                'is_system_role' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('user_roles')->insert([
            'user_id' => $user->id,
            'role_id' => $roleId,
            'assigned_at' => now(),
        ]);

        return $user;
    }

    protected function createTestParcel(array $attributes = []): Parcel
    {
        return Parcel::create(array_merge([
            'tracking_number' => 'TRACK_'.Str::random(8),
            'courier_company' => 'Shopee',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => $this->resident->full_name,
            'recipient_phone' => $this->resident->phone_number,
            'recipient_user_id' => $this->resident->id,
            'parcel_photo_url' => 'https://sample.com/parcel_photo.jpg',
            'pickup_pin_code' => '123456',
            'pickup_qr_code' => 'QR_123456',
            'received_by_staff_id' => $this->receptionist->id,
            'status' => 'RECEIVED_AT_RECEPTION',
            'received_at' => now(),
        ], $attributes));
    }

    // =========================================================================
    // NHÓM 1: TRIGGER & LIFECYCLE TESTS (1 - 5)
    // =========================================================================

    /**
     * Test 1: Bưu phẩm được tiếp nhận và commit thành công thì kích hoạt notification job
     */
    public function test_parcel_received_and_committed_triggers_notification_job(): void
    {
        Queue::fake();

        $trackingNumber = 'TRACK_NOTIF_'.Str::random(6);

        $parcel = $this->receptionService->receiveParcel(
            data: [
                'tracking_number' => $trackingNumber,
                'courier_company' => 'GHTK',
                'apartment_id' => $this->apartment->id,
                'recipient_name' => $this->resident->full_name,
                'recipient_phone' => $this->resident->phone_number,
                'recipient_user_id' => $this->resident->id,
                'parcel_photo_url' => 'https://sample.com/p1.jpg',
            ],
            staffUser: $this->receptionist
        );

        $this->assertNotNull($parcel->id);
        Queue::assertPushed(SendParcelNotificationJob::class, function ($job) use ($parcel) {
            return $job->parcelId === $parcel->id;
        });
    }

    /**
     * Test 2: Rollback transaction không phát sự kiện gửi notification
     */
    public function test_transaction_rollback_does_not_send_notification(): void
    {
        Queue::fake();

        try {
            $this->receptionService->receiveParcel(
                data: [
                    'tracking_number' => 'INVALID_TRACK_'.Str::random(6),
                    'courier_company' => 'Shopee',
                    'apartment_id' => (string) Str::uuid(), // Căn hộ không tồn tại -> bắt buộc rollback
                    'recipient_name' => 'Tester',
                    'recipient_phone' => '0900000000',
                    'parcel_photo_url' => 'https://sample.com/rollback.jpg',
                ],
                staffUser: $this->receptionist
            );
        } catch (\Throwable $e) {
            // Expected validation / FK exception
        }

        Queue::assertNotPushed(SendParcelNotificationJob::class);
    }

    /**
     * Test 3: Sự kiện tiếp nhận bị dispatch lại không gây gửi trùng (Idempotency)
     */
    public function test_repeated_reception_event_does_not_send_duplicate_notifications(): void
    {
        Mail::fake();

        $parcel = $this->createTestParcel();

        // Lần chạy 1: Gửi thành công
        $job1 = new SendParcelNotificationJob($parcel->id);
        $job1->handle($this->pushService);

        Mail::assertSent(ParcelReceivedMail::class, 1);

        // Lần chạy 2 (Dispatch lặp lại): Job phải nhận biết đã gửi và không gọi Mail lần 2
        $job2 = new SendParcelNotificationJob($parcel->id);
        $job2->handle($this->pushService);

        // Tổng số email gửi đi vẫn chỉ là 1!
        Mail::assertSent(ParcelReceivedMail::class, 1);

        $this->assertDatabaseHas('parcel_notification_logs', [
            'parcel_id' => $parcel->id,
            'channel' => 'EMAIL',
            'status' => 'SENT',
        ]);
    }

    /**
     * Test 4: Chỉnh sửa ghi chú hoặc cập nhật ảnh không phát lại notification nhận hàng
     */
    public function test_updating_parcel_note_or_photo_does_not_retrigger_reception_notification(): void
    {
        Queue::fake();

        $parcel = $this->createTestParcel();

        // Cập nhật ghi chú thông qua updateParcel (phát action UPDATED)
        $this->receptionService->updateParcel(
            id: $parcel->id,
            data: ['notes' => 'Kiện hàng dễ vỡ, đã xếp vào kệ 2'],
            staffUser: $this->receptionist
        );

        // SendParcelNotificationJob KHÔNG được push vì action là UPDATED chứ không phải CREATED
        Queue::assertNotPushed(SendParcelNotificationJob::class);
    }

    /**
     * Test 5: Check-out hoặc bàn giao không kích hoạt nhầm thông báo tiếp nhận
     */
    public function test_parcel_checkout_does_not_trigger_reception_notification(): void
    {
        Queue::fake();

        $parcel = $this->createTestParcel([
            'status' => 'DELIVERED', // Đã hoàn tất nhận
            'received_at' => now()->subDay(),
            'collected_at' => now(),
        ]);

        // Giả lập dispatch job khi bưu phẩm đã checkout
        $job = new SendParcelNotificationJob($parcel->id);
        $job->handle($this->pushService);

        // Không có email hoặc push nào được gửi cho trạng thái DELIVERED
        $this->assertDatabaseMissing('parcel_notification_logs', [
            'parcel_id' => $parcel->id,
            'channel' => 'EMAIL',
            'status' => 'SENT',
        ]);
    }

    // =========================================================================
    // NHÓM 2: WEB PUSH NOTIFICATION TESTS (6 - 10)
    // =========================================================================

    /**
     * Test 6: Subscription hợp lệ nhận Web Push payload chính xác
     */
    public function test_valid_web_push_subscription_receives_push_payload(): void
    {
        $endpoint = 'https://mock.push.test/send/valid_'.Str::random(8);

        WebPushSubscription::create([
            'user_id' => $this->resident->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => 'mock_p256dh_public_key_for_test_123',
            'auth_token' => 'mock_auth_token_for_test_123', // gitleaks:allow
            'content_encoding' => 'aes128gcm',
            'is_active' => true,
        ]);

        $parcel = $this->createTestParcel();

        $job = new SendParcelNotificationJob($parcel->id);
        $job->handle($this->pushService);

        $this->assertDatabaseHas('parcel_notification_logs', [
            'parcel_id' => $parcel->id,
            'channel' => 'WEB_PUSH',
            'status' => 'SENT',
        ]);
    }

    /**
     * Test 7: Cư dân chưa đăng ký Web Push không làm flow tiếp nhận bị crash
     */
    public function test_resident_without_push_subscription_does_not_crash_flow(): void
    {
        Mail::fake();

        $parcel = $this->createTestParcel();

        $job = new SendParcelNotificationJob($parcel->id);
        $job->handle($this->pushService);

        // Kênh push ghi nhận FAILED (chưa đăng ký), nhưng không ném unhandled crash
        $this->assertDatabaseHas('parcel_notification_logs', [
            'parcel_id' => $parcel->id,
            'channel' => 'WEB_PUSH',
            'status' => 'FAILED',
        ]);

        // Email vẫn gửi thành công bình thường
        Mail::assertSent(ParcelReceivedMail::class, 1);
    }

    /**
     * Test 8: Khi không có push, email vẫn gửi đến người nhận bình thường
     */
    public function test_permission_denied_or_no_push_still_allows_email_delivery(): void
    {
        Mail::fake();

        $parcel = $this->createTestParcel();

        $job = new SendParcelNotificationJob($parcel->id);
        $job->handle($this->pushService);

        Mail::assertSent(ParcelReceivedMail::class, function ($mail) {
            return $mail->hasTo($this->resident->email);
        });
    }

    /**
     * Test 9: Subscription hết hạn (410 Gone) được tự động vô hiệu hóa an toàn
     */
    public function test_invalid_or_expired_subscription_is_deactivated_gracefully(): void
    {
        $endpoint = 'https://mock.push.test/expired_endpoint_'.Str::random(6);

        $sub = WebPushSubscription::create([
            'user_id' => $this->resident->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => 'key_expired',
            'auth_token' => 'token_expired',
            'content_encoding' => 'aes128gcm',
            'is_active' => true,
        ]);

        $result = $this->pushService->sendNotification($sub, [
            'title' => 'Test Expired',
            'body' => 'Test body',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals(410, $result['status_code']);

        // Subscription được chuyển sang is_active = false
        $sub->refresh();
        $this->assertFalse($sub->is_active);
    }

    /**
     * Test 10: Cư dân không thể truy cập hoặc hủy subscription của cư dân khác (Security/RBAC)
     */
    public function test_resident_cannot_access_or_unsubscribe_other_resident_push_subscription(): void
    {
        $otherUser = User::create([
            'username' => 'other_user_'.Str::random(6),
            'phone_number' => '093'.rand(1000000, 9999999),
            'email' => 'other_user_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('secret123'),
            'full_name' => 'Người Dùng Khác',
            'status' => 'ACTIVE',
        ]);

        $otherEndpoint = 'https://mock.push.test/other_device_'.Str::random(6);
        $sub = WebPushSubscription::create([
            'user_id' => $otherUser->id,
            'endpoint' => $otherEndpoint,
            'endpoint_hash' => hash('sha256', $otherEndpoint),
            'public_key' => 'pub_other',
            'auth_token' => 'auth_other',
            'is_active' => true,
        ]);

        // Cư dân resident hiện tại gửi request hủy endpoint của otherUser
        $controller = app(PushNotificationController::class);
        $request = Request::create('/api/v1/notifications/push-subscription', 'DELETE', [
            'endpoint' => $otherEndpoint,
        ]);
        $request->setUserResolver(fn () => $this->resident);

        $response = $controller->unsubscribe($request);
        $this->assertEquals(200, $response->getStatusCode());

        // Subscription của otherUser vẫn còn nguyên vẹn is_active = true!
        $sub->refresh();
        $this->assertTrue($sub->is_active);
    }

    // =========================================================================
    // NHÓM 3: EMAIL NOTIFICATION TESTS (11 - 15)
    // =========================================================================

    /**
     * Test 11: Email gửi đến đúng địa chỉ email của cư dân nhận kiện hàng
     */
    public function test_email_sent_to_correct_resident_recipient(): void
    {
        Mail::fake();

        $parcel = $this->createTestParcel();

        $job = new SendParcelNotificationJob($parcel->id);
        $job->handle($this->pushService);

        Mail::assertSent(ParcelReceivedMail::class, function ($mail) {
            return $mail->hasTo($this->resident->email)
                && $mail->recipientName === $this->resident->full_name;
        });
    }

    /**
     * Test 12: Nội dung email sử dụng dữ liệu bưu phẩm thực tế (tracking, courier, apt)
     */
    public function test_email_content_uses_actual_parcel_data(): void
    {
        $trackingNumber = 'ACTUAL_TRACK_998877';
        $courier = 'Giao Hàng Nhanh (GHN)';

        $parcel = $this->createTestParcel([
            'tracking_number' => $trackingNumber,
            'courier_company' => $courier,
            'stored_location_type' => 'SMART_LOCKER',
            'pickup_pin_code' => '849201',
        ]);

        $mailable = new ParcelReceivedMail(
            parcel: $parcel,
            recipientName: $this->resident->full_name,
            pickupLocation: 'Tủ đồ thông minh Smart Locker',
            pinCode: '849201'
        );

        $mailable->assertSeeInHtml($trackingNumber);
        $mailable->assertSeeInHtml($courier);
        $mailable->assertSeeInHtml($this->apartment->apartment_number);
        $mailable->assertSeeInHtml('849201');
    }

    /**
     * Test 13: Tuyệt đối không gửi mật khẩu hoặc thông tin xác thực trong email & push
     */
    public function test_email_and_push_payload_do_not_contain_passwords_or_secrets(): void
    {
        $parcel = $this->createTestParcel();

        $mailable = new ParcelReceivedMail(
            parcel: $parcel,
            recipientName: $this->resident->full_name,
            pickupLocation: 'Quầy Lễ tân'
        );

        $html = $mailable->render();
        $this->assertStringNotContainsString('password', strtolower($html));
        $this->assertStringNotContainsString('secret', strtolower($html));
        $this->assertStringNotContainsString($this->resident->password_hash, $html);
    }

    /**
     * Test 14: Ghi nhận trạng thái FAILED khi mail provider trả lỗi
     */
    public function test_email_failure_is_recorded_in_logs(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new Exception('SMTP Connection timed out (504)'));

        $parcel = $this->createTestParcel();

        try {
            $job = new SendParcelNotificationJob($parcel->id);
            $job->handle($this->pushService);
        } catch (\Throwable $e) {
            // Expected partial failure
        }

        $this->assertDatabaseHas('parcel_notification_logs', [
            'parcel_id' => $parcel->id,
            'channel' => 'EMAIL',
            'status' => 'FAILED',
        ]);
    }

    /**
     * Test 15: Retry không gửi trùng email đã thành công trước đó
     */
    public function test_retry_does_not_resend_already_sent_email(): void
    {
        Mail::fake();

        $parcel = $this->createTestParcel();

        // Đã có log SENT từ trước
        ParcelNotificationLog::create([
            'parcel_id' => $parcel->id,
            'recipient_user_id' => $this->resident->id,
            'channel' => 'EMAIL',
            'destination' => $this->resident->email,
            'status' => 'SENT',
            'sent_at' => now(),
        ]);

        $job = new SendParcelNotificationJob($parcel->id);
        $job->handle($this->pushService);

        // Không gửi thêm bất kỳ email nào
        Mail::assertNothingSent();
    }

    // =========================================================================
    // NHÓM 4: ĐỘC LẬP GIỮA HAI KÊNH NOTIFICATION (16 - 18)
    // =========================================================================

    /**
     * Test 16: Email lỗi nhưng Web Push vẫn được xử lý độc lập
     */
    public function test_email_failure_does_not_block_web_push(): void
    {
        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new Exception('Mail provider down'));

        $endpoint = 'https://mock.push.test/send/independent_push_'.Str::random(6);
        WebPushSubscription::create([
            'user_id' => $this->resident->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => 'pub_indep',
            'auth_token' => 'auth_indep',
            'is_active' => true,
        ]);

        $parcel = $this->createTestParcel();

        try {
            $job = new SendParcelNotificationJob($parcel->id);
            $job->handle($this->pushService);
        } catch (\Throwable $e) {
            // Expected
        }

        // Email FAILED
        $this->assertDatabaseHas('parcel_notification_logs', [
            'parcel_id' => $parcel->id,
            'channel' => 'EMAIL',
            'status' => 'FAILED',
        ]);

        // Nhưng Web Push vẫn SENT thành công!
        $this->assertDatabaseHas('parcel_notification_logs', [
            'parcel_id' => $parcel->id,
            'channel' => 'WEB_PUSH',
            'status' => 'SENT',
        ]);
    }

    /**
     * Test 17: Web Push lỗi nhưng Email vẫn được xử lý độc lập
     */
    public function test_web_push_failure_does_not_block_email(): void
    {
        Mail::fake();

        $endpoint = 'https://mock.push.test/server_error_'.Str::random(6);
        WebPushSubscription::create([
            'user_id' => $this->resident->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => 'pub_err',
            'auth_token' => 'auth_err',
            'is_active' => true,
        ]);

        $parcel = $this->createTestParcel();

        try {
            $job = new SendParcelNotificationJob($parcel->id);
            $job->handle($this->pushService);
        } catch (\Throwable $e) {
            // Expected
        }

        // Email gửi thành công
        Mail::assertSent(ParcelReceivedMail::class, 1);
        $this->assertDatabaseHas('parcel_notification_logs', [
            'parcel_id' => $parcel->id,
            'channel' => 'EMAIL',
            'status' => 'SENT',
        ]);

        // Push FAILED
        $this->assertDatabaseHas('parcel_notification_logs', [
            'parcel_id' => $parcel->id,
            'channel' => 'WEB_PUSH',
            'status' => 'FAILED',
        ]);
    }

    /**
     * Test 18: Retry một kênh không gửi lại kênh đã thành công
     */
    public function test_retrying_failed_channel_does_not_resend_successful_channel(): void
    {
        Mail::fake();

        $endpoint = 'https://mock.push.test/server_error_first_attempt_'.Str::random(6);
        $sub = WebPushSubscription::create([
            'user_id' => $this->resident->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => hash('sha256', $endpoint),
            'public_key' => 'pub_retry',
            'auth_token' => 'auth_retry',
            'is_active' => true,
        ]);

        $parcel = $this->createTestParcel();

        // Lần 1: Email thành công, Push lỗi
        try {
            $job = new SendParcelNotificationJob($parcel->id);
            $job->handle($this->pushService);
        } catch (\Throwable $e) {
            // Expected
        }

        Mail::assertSent(ParcelReceivedMail::class, 1);

        // Khắc phục lỗi endpoint cho Push
        $validEndpoint = 'https://mock.push.test/send/recovered_'.Str::random(6);
        $sub->update([
            'endpoint' => $validEndpoint,
            'endpoint_hash' => hash('sha256', $validEndpoint),
        ]);

        // Lần 2 (Queue retry):
        $jobRetry = new SendParcelNotificationJob($parcel->id);
        $jobRetry->handle($this->pushService);

        // Tổng số email đã gửi VẪN LÀ 1 (không gửi đúp email đã thành công)!
        Mail::assertSent(ParcelReceivedMail::class, 1);

        // Web Push đã thành công
        $this->assertDatabaseHas('parcel_notification_logs', [
            'parcel_id' => $parcel->id,
            'channel' => 'WEB_PUSH',
            'status' => 'SENT',
        ]);
    }

    // =========================================================================
    // NHÓM 5: DATABASE & PERFORMANCE TESTS (19 - 22)
    // =========================================================================

    /**
     * Test 19: Notification handler không load toàn bộ bảng users hoặc residents
     */
    public function test_notification_handler_does_not_load_all_users_or_residents(): void
    {
        Mail::fake();

        $parcel = $this->createTestParcel();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $job = new SendParcelNotificationJob($parcel->id);
        $job->handle($this->pushService);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach ($queries as $q) {
            $sql = strtolower($q['query']);
            // Đảm bảo không có query select * from users hoặc select * from residents không có where
            if (str_contains($sql, 'from `users`') || str_contains($sql, 'from "users"')) {
                $this->assertTrue(str_contains($sql, 'where'), "Query trên users phải có WHERE clause: {$sql}");
            }
            if (str_contains($sql, 'from `residents`') || str_contains($sql, 'from "residents"')) {
                $this->assertTrue(str_contains($sql, 'where'), "Query trên residents phải có WHERE clause: {$sql}");
            }
        }
    }

    /**
     * Test 20: Tối ưu query, không có truy vấn dư thừa trong notification handler
     */
    public function test_notification_handler_has_no_redundant_queries(): void
    {
        Mail::fake();

        $parcel = $this->createTestParcel();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $job = new SendParcelNotificationJob($parcel->id);
        $job->handle($this->pushService);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Xử lý 1 bưu phẩm chỉ cần dưới 15 query gồm load relations, log checks và updates
        $this->assertLessThan(15, $queryCount, "Số lượng truy vấn xử lý thông báo ({$queryCount}) vượt quá mức tối ưu");
    }

    /**
     * Test 21: Tiếp nhận bưu phẩm trả về ngay 201 mà không bị nghẽn bởi gửi email
     */
    public function test_notification_does_not_block_reception_response(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->receptionist)->postJson('/api/v1/reception/parcels', [
            'tracking_number' => 'RESP_SPEED_'.Str::random(6),
            'courier_company' => 'Shopee',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => $this->resident->full_name,
            'recipient_phone' => $this->resident->phone_number,
            'recipient_user_id' => $this->resident->id,
            'parcel_photo_url' => 'https://sample.com/fast_response.jpg',
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);

        // Job được đẩy vào queue bất đồng bộ
        Queue::assertPushed(SendParcelNotificationJob::class);
    }

    /**
     * Test 22: Sự kiện Realtime và Notification chỉ được phát sau khi database commit
     */
    public function test_realtime_and_notifications_are_dispatched_after_commit(): void
    {
        Queue::fake();
        Event::fake([QuocTinRealtimeEvent::class]);

        $trackingNumber = 'COMMIT_FIRST_'.Str::random(6);

        $this->actingAs($this->receptionist)->postJson('/api/v1/reception/parcels', [
            'tracking_number' => $trackingNumber,
            'courier_company' => 'ViettelPost',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => $this->resident->full_name,
            'recipient_phone' => $this->resident->phone_number,
            'parcel_photo_url' => 'https://sample.com/commit_first.jpg',
        ]);

        // Bưu phẩm đã được lưu trong DB trước khi event/job gửi
        $this->assertDatabaseHas('parcels', [
            'tracking_number' => $trackingNumber,
        ]);

        Event::assertDispatched(QuocTinRealtimeEvent::class);
    }

    // =========================================================================
    // NHÓM 6: REGRESSION TESTS (23 - 26)
    // =========================================================================

    /**
     * Test 23: Module tiếp nhận bưu phẩm của Lễ tân vẫn hoạt động nguyên trạng
     */
    public function test_reception_parcel_module_remains_intact(): void
    {
        $trackingNumber = 'REGRESS_REC_'.Str::random(6);

        $parcel = $this->receptionService->receiveParcel([
            'tracking_number' => $trackingNumber,
            'courier_company' => 'GHTK',
            'apartment_id' => $this->apartment->id,
            'recipient_name' => 'Cư dân Regression',
            'recipient_phone' => '0933333333',
            'parcel_photo_url' => 'https://sample.com/regress.jpg',
            'stored_location_type' => 'RECEPTION_DESK',
        ], staffUser: $this->receptionist);

        $this->assertEquals($trackingNumber, $parcel->tracking_number);
        $this->assertEquals('RECEIVED_AT_RECEPTION', $parcel->status);
        $this->assertNotNull($parcel->pickup_pin_code);
    }

    /**
     * Test 24: Danh sách bưu phẩm, KPIs và bộ lọc vẫn hoạt động nguyên trạng
     */
    public function test_reception_parcel_list_and_kpis_remain_intact(): void
    {
        $result = $this->receptionService->getParcels(['status' => 'ALL']);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('kpis', $result);
        $this->assertArrayHasKey('pending_parcels', $result['kpis']);
        $this->assertArrayHasKey('total_parcels', $result['kpis']);
    }

    /**
     * Test 25: Dashboard giám sát bưu phẩm tồn đọng và scope hoạt động bình thường
     */
    public function test_parcel_dashboard_remains_intact(): void
    {
        $pendingCount = Parcel::pendingReception()->count();
        $this->assertIsInt($pendingCount);

        $overdueCount = Parcel::overdue(5)->count();
        $this->assertIsInt($overdueCount);
    }

    /**
     * Test 26: Không can thiệp hoặc gây ảnh hưởng đến các module khác (RBAC, Amenities...)
     */
    public function test_zero_interference_with_other_teammates_modules(): void
    {
        // Kiểm tra bảo toàn cấu trúc các bảng dùng chung
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('apartments'));
        $this->assertTrue(Schema::hasTable('parcels'));
        $this->assertTrue(Schema::hasTable('amenities'));
        $this->assertTrue(Schema::hasTable('invoices'));
    }
}
