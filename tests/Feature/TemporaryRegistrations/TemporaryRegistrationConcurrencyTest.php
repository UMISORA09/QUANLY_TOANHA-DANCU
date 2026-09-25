<?php

namespace Tests\Feature\TemporaryRegistrations;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\TemporaryRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TemporaryRegistrationConcurrencyTest extends TestCase
{
    protected function createAdminUser(string $nameSuffix = ''): array
    {
        $user = User::create([
            'username' => 'adm_cc_'.Str::random(6).$nameSuffix,
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'adm_cc_'.Str::random(6).$nameSuffix.'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin Concurrency '.$nameSuffix,
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'SUPER_ADMIN')->first();
        if ($role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'role_id' => $role->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $token = 'smart_token_cc_'.$user->id.'_'.Str::random(32);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'Concurrency Tester',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'ConcurrencyTestAgent',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    protected function createTestRecord(array $attributes = []): TemporaryRegistration
    {
        $apt = Apartment::first();
        if (! $apt) {
            $this->markTestSkipped('Không tìm thấy căn hộ trong CSDL.');
        }

        $residentUser = User::create([
            'username' => 'res_cc_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'res_cc_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Cư Dân Concurrency',
            'status' => 'ACTIVE',
        ]);

        $resident = Resident::create([
            'user_id' => $residentUser->id,
            'apartment_id' => $apt->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'relationship_to_head' => 'TENANT',
            'is_active' => true,
        ]);

        $default = [
            'resident_id' => $resident->id,
            'apartment_id' => $apt->id,
            'registration_type' => 'TEMPORARY_STAY',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'reason' => 'Đăng ký tạm trú kiểm thử Concurrency',
            'police_status' => 'PENDING_POLICE_SUBMISSION',
            'notes' => 'Ghi chú khởi tạo',
        ];

        return TemporaryRegistration::create(array_merge($default, $attributes));
    }

    /**
     * 1. Concurrent Delete: Hai Admin cùng DELETE một hồ sơ đồng thời qua 2 kết nối HTTP thật
     * Yêu cầu: Đúng 1 request thành công 200, request kia trả 404, không crash 500, DB sạch.
     */
    public function test_01_concurrent_delete_delete_only_one_succeeds(): void
    {
        [$admin1, $token1] = $this->createAdminUser('DelA');
        [$admin2, $token2] = $this->createAdminUser('DelB');

        $record = $this->createTestRecord();
        $targetUrl = "http://127.0.0.1:8000/api/v1/residents/temporary-registrations/{$record->id}";

        $mh = curl_multi_init();

        $ch1 = curl_init();
        curl_setopt_array($ch1, [
            CURLOPT_URL => $targetUrl,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token1,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $ch2 = curl_init();
        curl_setopt_array($ch2, [
            CURLOPT_URL => $targetUrl,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token2,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $code1 = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
        $body1 = curl_multi_getcontent($ch1);
        $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $body2 = curl_multi_getcontent($ch2);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);
        curl_multi_close($mh);
        curl_close($ch1);
        curl_close($ch2);

        $codes = [$code1, $code2];
        sort($codes);

        // Kỳ vọng: Đúng 1 request thành công 200, 1 request trả 404 (Không tìm thấy / đã bị xóa)
        $this->assertEquals([200, 404], $codes, "Double delete fail: Expected [200, 404], got [{$code1}, {$code2}]. Body1: {$body1}, Body2: {$body2}");

        // Kiểm tra không có 500
        $this->assertNotEquals(500, $code1);
        $this->assertNotEquals(500, $code2);

        // Kiểm tra database cuối cùng: Record đã bị xóa hoàn toàn khỏi DB
        $this->assertNull(TemporaryRegistration::find($record->id));
    }

    /**
     * 2. Concurrent Update (Same Field): Hai Admin cùng sửa field 'reason' đồng thời
     * Yêu cầu: Không Lost Update, một request thành công 200, request còn lại 409 Conflict.
     */
    public function test_02_concurrent_update_same_field_detects_conflict_and_prevents_lost_update(): void
    {
        [$admin1, $token1] = $this->createAdminUser('UpdA');
        [$admin2, $token2] = $this->createAdminUser('UpdB');

        $record = $this->createTestRecord(['reason' => 'Old reason']);
        $snapshotUpdatedAt = $record->updated_at->toISOString();

        $targetUrl = "http://127.0.0.1:8000/api/v1/residents/temporary-registrations/{$record->id}";

        $mh = curl_multi_init();

        $ch1 = curl_init();
        curl_setopt_array($ch1, [
            CURLOPT_URL => $targetUrl,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode([
                'reason' => 'Reason from Admin A',
                'updated_at' => $snapshotUpdatedAt,
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token1,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $ch2 = curl_init();
        curl_setopt_array($ch2, [
            CURLOPT_URL => $targetUrl,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode([
                'reason' => 'Reason from Admin B',
                'updated_at' => $snapshotUpdatedAt,
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token2,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $code1 = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
        $body1 = curl_multi_getcontent($ch1);
        $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $body2 = curl_multi_getcontent($ch2);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);
        curl_multi_close($mh);
        curl_close($ch1);
        curl_close($ch2);

        $codes = [$code1, $code2];
        sort($codes);

        // Kỳ vọng: Đúng 1 request thành công 200 và 1 request nhận 409 Conflict
        $this->assertEquals([200, 409], $codes, "Concurrent update fail: Expected [200, 409], got [{$code1}, {$code2}]. Body1: {$body1}, Body2: {$body2}");

        // Kiểm tra response 409 có message rõ ràng
        $conflictBody = ($code1 === 409) ? json_decode($body1, true) : json_decode($body2, true);
        $this->assertFalse($conflictBody['success']);
        $this->assertEquals('Hồ sơ đã được Admin khác cập nhật. Vui lòng tải lại dữ liệu mới nhất.', $conflictBody['message']);

        // Kiểm tra database: Giữ nguyên dữ liệu của người thắng, không bị ghi đè âm thầm
        $finalRecord = TemporaryRegistration::find($record->id);
        $this->assertNotNull($finalRecord);
        $this->assertContains($finalRecord->reason, ['Reason from Admin A', 'Reason from Admin B']);
    }

    /**
     * 3. Concurrent Update (Different Fields): Admin A sửa 'reason', Admin B sửa 'notes'
     * Yêu cầu: Không merge âm thầm từ snapshot cũ, một request thành công, request kia 409 Conflict.
     */
    public function test_03_concurrent_update_different_fields_detects_conflict(): void
    {
        [$admin1, $token1] = $this->createAdminUser('DiffA');
        [$admin2, $token2] = $this->createAdminUser('DiffB');

        $record = $this->createTestRecord([
            'reason' => 'Old Reason',
            'notes' => 'Old Notes',
        ]);
        $snapshotUpdatedAt = $record->updated_at->toISOString();

        $targetUrl = "http://127.0.0.1:8000/api/v1/residents/temporary-registrations/{$record->id}";

        $mh = curl_multi_init();

        $ch1 = curl_init();
        curl_setopt_array($ch1, [
            CURLOPT_URL => $targetUrl,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode([
                'reason' => 'New Reason A',
                'updated_at' => $snapshotUpdatedAt,
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token1,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $ch2 = curl_init();
        curl_setopt_array($ch2, [
            CURLOPT_URL => $targetUrl,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode([
                'notes' => 'New Notes B',
                'updated_at' => $snapshotUpdatedAt,
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token2,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $code1 = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
        $body1 = curl_multi_getcontent($ch1);
        $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $body2 = curl_multi_getcontent($ch2);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);
        curl_multi_close($mh);
        curl_close($ch1);
        curl_close($ch2);

        $codes = [$code1, $code2];
        sort($codes);

        $this->assertEquals([200, 409], $codes, "Diff fields update fail: Expected [200, 409], got [{$code1}, {$code2}]. Body1: {$body1}, Body2: {$body2}");

        // Kiểm tra database: Không bị ghi đè dữ liệu stale
        $finalRecord = TemporaryRegistration::find($record->id);
        $this->assertNotNull($finalRecord);
    }

    /**
     * 4. Concurrent Update & Delete: Admin A cập nhật trong khi Admin B xóa đồng thời
     * Yêu cầu: Không 500, nếu delete commit trước thì update trả 404, nếu update trước thì delete trả 200.
     * Record cuối cùng phải bị xóa hoặc giữ trạng thái nhất quán, không tái sinh.
     */
    public function test_04_concurrent_update_and_delete(): void
    {
        [$admin1, $token1] = $this->createAdminUser('UpdDelA');
        [$admin2, $token2] = $this->createAdminUser('UpdDelB');

        $record = $this->createTestRecord(['reason' => 'Reason Before Concurrency']);
        $snapshotUpdatedAt = $record->updated_at->toISOString();

        $targetUrl = "http://127.0.0.1:8000/api/v1/residents/temporary-registrations/{$record->id}";

        $mh = curl_multi_init();

        $chUpd = curl_init();
        curl_setopt_array($chUpd, [
            CURLOPT_URL => $targetUrl,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode([
                'reason' => 'Updated Reason',
                'updated_at' => $snapshotUpdatedAt,
            ]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token1,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $chDel = curl_init();
        curl_setopt_array($chDel, [
            CURLOPT_URL => $targetUrl,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token2,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        curl_multi_add_handle($mh, $chUpd);
        curl_multi_add_handle($mh, $chDel);

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $updCode = curl_getinfo($chUpd, CURLINFO_HTTP_CODE);
        $updBody = curl_multi_getcontent($chUpd);
        $delCode = curl_getinfo($chDel, CURLINFO_HTTP_CODE);
        $delBody = curl_multi_getcontent($chDel);

        curl_multi_remove_handle($mh, $chUpd);
        curl_multi_remove_handle($mh, $chDel);
        curl_multi_close($mh);
        curl_close($chUpd);
        curl_close($chDel);

        // Tuyệt đối không được xảy ra 500 Server Error
        $this->assertNotEquals(500, $updCode, "Update returned 500: {$updBody}");
        $this->assertNotEquals(500, $delCode, "Delete returned 500: {$delBody}");

        // Kết quả hợp lệ: Hoặc (Upd 200, Del 200) hoặc (Del 200, Upd 404)
        $validScenarios = [
            [200, 200], // Upd chạy trước, Del chạy sau
            [200, 404], // Del chạy trước, Upd chạy sau nhận 404
        ];
        $actualCodes = [$delCode, $updCode];
        $this->assertTrue(
            in_array($actualCodes, $validScenarios, true) || in_array([$updCode, $delCode], $validScenarios, true),
            "Unexpected status codes: Update={$updCode}, Delete={$delCode}. UpdBody: {$updBody}, DelBody: {$delBody}"
        );

        // Kiểm tra tính nhất quán cuối cùng: Record đã bị xóa (vì delete luôn thành công ở cả 2 kịch bản)
        $this->assertNull(TemporaryRegistration::find($record->id));
    }

    /**
     * 5. Delete hoàn tất trước rồi Update: Update không được thành công và không khôi phục record
     */
    public function test_05_delete_then_update_never_resurrects_record(): void
    {
        [$admin1, $token1] = $this->createAdminUser('SeqDel');
        [$admin2, $token2] = $this->createAdminUser('SeqUpd');

        $record = $this->createTestRecord();

        // Admin 1 xóa thành công
        $resDel = $this->withHeader('Authorization', 'Bearer '.$token1)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}");
        $resDel->assertStatus(200);

        // Admin 2 cố tình update record vừa bị xóa
        $resUpd = $this->withHeader('Authorization', 'Bearer '.$token2)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Cố tình khôi phục',
            ]);

        $resUpd->assertStatus(404);
        $resUpd->assertJsonPath('success', false);
        $resUpd->assertJsonPath('message', 'Hồ sơ đã được Admin khác xóa hoặc không còn tồn tại.');

        // Kiểm tra database: Không bị tái sinh / recreate
        $this->assertDatabaseMissing('temporary_registrations', ['id' => $record->id]);
    }

    /**
     * 6. Stale Update với updated_at cũ: Phải trả về 409 Conflict
     */
    public function test_06_stale_update_returns_409_conflict(): void
    {
        [$admin, $token] = $this->createAdminUser('Stale');
        $record = $this->createTestRecord();

        // Giả lập record đã được cập nhật trước đó
        $record->reason = 'Lý do mới nhất trong DB';
        $record->save();

        // Gửi update với updated_at lùi về 1 giờ trước
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$record->id}", [
                'reason' => 'Cập nhật với timestamp cũ',
                'updated_at' => now()->subHours(1)->toISOString(),
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Hồ sơ đã được Admin khác cập nhật. Vui lòng tải lại dữ liệu mới nhất.');
    }

    /**
     * 7. Duplicate Delete: Gọi DELETE lần 2 phải trả về 404 sạch sẽ, không 500
     */
    public function test_07_duplicate_delete_returns_404_cleanly(): void
    {
        [$admin, $token] = $this->createAdminUser('DupDel');
        $record = $this->createTestRecord();

        // Lần 1: Thành công 200
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}")
            ->assertStatus(200);

        // Lần 2: 404 Không tìm thấy, thông báo rõ ràng
        $res2 = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$record->id}");

        $res2->assertStatus(404);
        $res2->assertJsonPath('success', false);
        $res2->assertJsonPath('message', 'Hồ sơ đã được Admin khác xóa hoặc không còn tồn tại.');
    }

    /**
     * 8. Không bao giờ lộ Exception 500 hoặc stack trace ra bên ngoài
     */
    public function test_08_no_unhandled_500_or_stack_trace_on_concurrency(): void
    {
        [$admin, $token] = $this->createAdminUser('Safe');
        $fakeUuid = (string) Str::uuid();

        // Update record ảo -> 404 sạch, có cấu trúc JSON chuẩn
        $resPut = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/residents/temporary-registrations/{$fakeUuid}", [
                'reason' => 'Test Safe Update',
                'updated_at' => now()->toISOString(),
            ]);
        $resPut->assertStatus(404);
        $resPut->assertJsonStructure(['success', 'message', 'error']);
        $this->assertStringNotContainsString('Stack trace', $resPut->getContent());
        $this->assertStringNotContainsString('ModelNotFoundException', $resPut->getContent());

        // Delete record ảo -> 404 sạch, có cấu trúc JSON chuẩn
        $resDel = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/residents/temporary-registrations/{$fakeUuid}");
        $resDel->assertStatus(404);
        $resDel->assertJsonStructure(['success', 'message', 'error']);
        $this->assertStringNotContainsString('Stack trace', $resDel->getContent());
    }

    /**
     * 9. Concurrent Approve: Hai Admin cùng phê duyệt một hồ sơ đồng thời qua 2 kết nối HTTP thật
     */
    public function test_24_concurrent_approve_only_one_succeeds(): void
    {
        [$admin1, $token1] = $this->createAdminUser('A');
        [$admin2, $token2] = $this->createAdminUser('B');

        $record = $this->createTestRecord();
        $targetUrl = "http://127.0.0.1:8000/api/v1/residents/temporary-registrations/{$record->id}/approve";

        $mh = curl_multi_init();

        $ch1 = curl_init();
        curl_setopt_array($ch1, [
            CURLOPT_URL => $targetUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['notes' => 'Admin 1 Approve']),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token1,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $ch2 = curl_init();
        curl_setopt_array($ch2, [
            CURLOPT_URL => $targetUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['notes' => 'Admin 2 Approve']),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token2,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        curl_multi_add_handle($mh, $ch1);
        curl_multi_add_handle($mh, $ch2);

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $code1 = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
        $body1 = curl_multi_getcontent($ch1);

        $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $body2 = curl_multi_getcontent($ch2);

        curl_multi_remove_handle($mh, $ch1);
        curl_multi_remove_handle($mh, $ch2);
        curl_multi_close($mh);
        curl_close($ch1);
        curl_close($ch2);

        $codes = [$code1, $code2];
        sort($codes);

        // Kiểm tra kết quả: Phải có đúng 1 request thành công 200 và 1 request bị từ chối do xung đột (409 Conflict)
        $this->assertEquals([200, 409], $codes, "Concurrency test fail: Expected HTTP codes [200, 409], got [{$code1}, {$code2}]. Body1: {$body1}, Body2: {$body2}");

        // Kiểm tra database cuối cùng: Trạng thái chỉ là APPROVED và chỉ có 1 người duyệt được ghi nhận
        $finalRecord = TemporaryRegistration::find($record->id);
        $this->assertEquals('APPROVED', $finalRecord->police_status);
        $this->assertNotNull($finalRecord->reviewed_by);
        $this->assertContains($finalRecord->reviewed_by, [$admin1->id, $admin2->id]);
        $this->assertNotNull($finalRecord->reviewed_at);
    }

    /**
     * 10. Concurrent Approve & Reject: Một Admin phê duyệt và một Admin từ chối đồng thời
     */
    public function test_25_concurrent_approve_and_reject_only_one_succeeds(): void
    {
        [$admin1, $token1] = $this->createAdminUser('ApproveAdmin');
        [$admin2, $token2] = $this->createAdminUser('RejectAdmin');

        $record = $this->createTestRecord();

        $approveUrl = "http://127.0.0.1:8000/api/v1/residents/temporary-registrations/{$record->id}/approve";
        $rejectUrl = "http://127.0.0.1:8000/api/v1/residents/temporary-registrations/{$record->id}/reject";

        $mh = curl_multi_init();

        $chApprove = curl_init();
        curl_setopt_array($chApprove, [
            CURLOPT_URL => $approveUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['notes' => 'Admin Approve Action']),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token1,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $chReject = curl_init();
        curl_setopt_array($chReject, [
            CURLOPT_URL => $rejectUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['notes' => 'Admin Reject Action (Thiếu giấy tờ)']),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token2,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        curl_multi_add_handle($mh, $chApprove);
        curl_multi_add_handle($mh, $chReject);

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 0.05);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $approveCode = curl_getinfo($chApprove, CURLINFO_HTTP_CODE);
        $approveBody = curl_multi_getcontent($chApprove);

        $rejectCode = curl_getinfo($chReject, CURLINFO_HTTP_CODE);
        $rejectBody = curl_multi_getcontent($chReject);

        curl_multi_remove_handle($mh, $chApprove);
        curl_multi_remove_handle($mh, $chReject);
        curl_multi_close($mh);
        curl_close($chApprove);
        curl_close($chReject);

        $codes = [$approveCode, $rejectCode];
        sort($codes);

        // Phải có đúng một thao tác thành công (200) và thao tác còn lại bị chặn bởi State Guard (409 Conflict)
        $this->assertEquals([200, 409], $codes, "Approve/Reject concurrency fail: Expected HTTP codes [200, 409], got Approve={$approveCode}, Reject={$rejectCode}. ApproveBody: {$approveBody}, RejectBody: {$rejectBody}");

        // Kiểm tra database cuối cùng: Trạng thái cuối phải là APPROVED hoặc REJECTED, không bao giờ ở trạng thái lưỡng lự
        $finalRecord = TemporaryRegistration::find($record->id);
        $this->assertContains($finalRecord->police_status, ['APPROVED', 'REJECTED']);
        $this->assertNotNull($finalRecord->reviewed_by);
        $this->assertNotNull($finalRecord->reviewed_at);

        if ($finalRecord->police_status === 'APPROVED') {
            $this->assertEquals($admin1->id, $finalRecord->reviewed_by);
        } else {
            $this->assertEquals($admin2->id, $finalRecord->reviewed_by);
        }
    }
}
