<?php

namespace Tests\Feature\Residents;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentConcurrencyTest extends TestCase
{
    protected string $baseUrl = 'http://127.0.0.1:8000/api/v1';

    /**
     * Tạo tài khoản Admin kèm token phiên đăng nhập
     */
    protected function createAdmin(string $tag): array
    {
        $user = User::create([
            'username' => 'adm_'.strtolower($tag).'_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'adm_'.strtolower($tag).'_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin Test '.$tag,
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

        $token = 'smart_token_'.$user->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $token);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'PHPUnit Concurrency Test',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo căn hộ riêng biệt phục vụ test concurrency
     */
    protected function createTestApartment(string $code = 'CONC'): Apartment
    {
        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();

        return Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $block->id ?? (string) Str::uuid(),
            'floor_id' => $floor->id ?? (string) Str::uuid(),
            'apartment_number' => $code.'-'.rand(100, 999),
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);
    }

    /**
     * Gửi 2 request HTTP gần như đồng thời qua curl_multi
     *
     * @param  array{method: string, url: string, token: string, body?: array}  $reqA
     * @param  array{method: string, url: string, token: string, body?: array}  $reqB
     * @return array{
     *     resA: array{status: int, body: string, data: mixed, start: float, end: float, duration: float},
     *     resB: array{status: int, body: string, data: mixed, start: float, end: float, duration: float}
     * }
     */
    protected function executeConcurrentHttpRequests(array $reqA, array $reqB): array
    {
        $mh = curl_multi_init();

        $chA = curl_init();
        $headersA = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer '.$reqA['token'],
        ];
        curl_setopt($chA, CURLOPT_URL, $reqA['url']);
        curl_setopt($chA, CURLOPT_CUSTOMREQUEST, $reqA['method']);
        curl_setopt($chA, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chA, CURLOPT_HTTPHEADER, $headersA);
        if (isset($reqA['body'])) {
            curl_setopt($chA, CURLOPT_POSTFIELDS, json_encode($reqA['body']));
        }

        $chB = curl_init();
        $headersB = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer '.$reqB['token'],
        ];
        curl_setopt($chB, CURLOPT_URL, $reqB['url']);
        curl_setopt($chB, CURLOPT_CUSTOMREQUEST, $reqB['method']);
        curl_setopt($chB, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chB, CURLOPT_HTTPHEADER, $headersB);
        if (isset($reqB['body'])) {
            curl_setopt($chB, CURLOPT_POSTFIELDS, json_encode($reqB['body']));
        }

        $startA = microtime(true);
        $startB = microtime(true);

        curl_multi_add_handle($mh, $chA);
        curl_multi_add_handle($mh, $chB);

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $endA = microtime(true);
        $endB = microtime(true);

        $bodyA = (string) curl_multi_getcontent($chA);
        $statusA = (int) curl_getinfo($chA, CURLINFO_HTTP_CODE);

        $bodyB = (string) curl_multi_getcontent($chB);
        $statusB = (int) curl_getinfo($chB, CURLINFO_HTTP_CODE);

        curl_multi_remove_handle($mh, $chA);
        curl_multi_remove_handle($mh, $chB);
        curl_multi_close($mh);

        return [
            'resA' => [
                'status' => $statusA,
                'body' => $bodyA,
                'data' => json_decode($bodyA, true),
                'start' => $startA,
                'end' => $endA,
                'duration' => round(($endA - $startA) * 1000, 2),
            ],
            'resB' => [
                'status' => $statusB,
                'body' => $bodyB,
                'data' => json_decode($bodyB, true),
                'start' => $startB,
                'end' => $endB,
                'duration' => round(($endB - $startB) * 1000, 2),
            ],
        ];
    }

    /**
     * Gửi tuần tự 1 request HTTP
     */
    protected function executeSingleHttpRequest(string $method, string $url, string $token, ?array $body = null): array
    {
        $ch = curl_init();
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer '.$token,
        ];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $start = microtime(true);
        $resBody = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $end = microtime(true);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $resBody,
            'data' => json_decode($resBody, true),
            'start' => $start,
            'end' => $end,
            'duration' => round(($end - $start) * 1000, 2),
        ];
    }

    /**
     * TC01: 2 Admin cùng sửa cùng 1 field (occupation) trên cùng 1 resident
     */
    public function test_tc01_concurrent_update_same_field(): void
    {
        [, $tokenA] = $this->createAdmin('A');
        [, $tokenB] = $this->createAdmin('B');
        $apt = $this->createTestApartment('TC01');

        $user = User::create([
            'username' => 'u_tc01_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'u_tc01_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Cư Dân TC01',
            'status' => 'ACTIVE',
        ]);

        $r1 = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'relationship_to_head' => 'SELF',
            'occupation' => 'Developer',
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $url = "{$this->baseUrl}/residents/{$r1->id}";
        $initialUpdatedAt = (string) $r1->updated_at;

        $results = $this->executeConcurrentHttpRequests(
            ['method' => 'PUT', 'url' => $url, 'token' => $tokenA, 'body' => ['occupation' => 'Teacher', 'updated_at' => $initialUpdatedAt]],
            ['method' => 'PUT', 'url' => $url, 'token' => $tokenB, 'body' => ['occupation' => 'Engineer', 'updated_at' => $initialUpdatedAt]]
        );

        $fresh = Resident::find($r1->id);
        $statuses = [$results['resA']['status'], $results['resB']['status']];

        echo PHP_EOL.'[TC01: SAME FIELD]'.PHP_EOL;
        echo 'Admin A Status: '.$results['resA']['status'].', Response: '.$results['resA']['body'].PHP_EOL;
        echo 'Admin B Status: '.$results['resB']['status'].', Response: '.$results['resB']['body'].PHP_EOL;
        echo 'Final DB Occupation: '.$fresh->occupation.', Updated at: '.$fresh->updated_at.PHP_EOL;

        $this->assertContains(200, $statuses, 'Một request cập nhật thành công');
        $this->assertContains(409, $statuses, 'Request thứ hai bị xung đột 409 Conflict');
        $this->assertTrue(in_array($fresh->occupation, ['Teacher', 'Engineer'], true));
    }

    /**
     * TC02: 2 Admin cùng sửa 2 field khác nhau trên cùng 1 resident (Partial Update with Stale Version)
     */
    public function test_tc02_concurrent_update_different_fields(): void
    {
        [, $tokenA] = $this->createAdmin('A');
        [, $tokenB] = $this->createAdmin('B');
        $apt = $this->createTestApartment('TC02');

        $user = User::create([
            'username' => 'u_tc02_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'u_tc02_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Cư Dân TC02',
            'status' => 'ACTIVE',
        ]);

        $r1 = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'relationship_to_head' => 'CHILD',
            'occupation' => 'Developer',
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $url = "{$this->baseUrl}/residents/{$r1->id}";
        $initialUpdatedAt = (string) $r1->updated_at;

        $results = $this->executeConcurrentHttpRequests(
            ['method' => 'PUT', 'url' => $url, 'token' => $tokenA, 'body' => ['occupation' => 'Teacher', 'updated_at' => $initialUpdatedAt]],
            ['method' => 'PUT', 'url' => $url, 'token' => $tokenB, 'body' => ['relationship_to_head' => 'SPOUSE', 'updated_at' => $initialUpdatedAt]]
        );

        $fresh = Resident::find($r1->id);
        $statuses = [$results['resA']['status'], $results['resB']['status']];

        echo PHP_EOL.'[TC02: DIFFERENT FIELDS]'.PHP_EOL;
        echo 'Admin A Status: '.$results['resA']['status'].', Response: '.$results['resA']['body'].PHP_EOL;
        echo 'Admin B Status: '.$results['resB']['status'].', Response: '.$results['resB']['body'].PHP_EOL;
        echo "Final DB Occupation: {$fresh->occupation}, Relationship: {$fresh->relationship_to_head}".PHP_EOL;

        $this->assertContains(200, $statuses);
        $this->assertContains(409, $statuses);
    }

    /**
     * TC03: 2 Admin cùng gửi FULL PAYLOAD (Stale Snapshot Overwrite / Lost Update)
     */
    public function test_tc03_full_payload_conflict(): void
    {
        [, $tokenA] = $this->createAdmin('A');
        [, $tokenB] = $this->createAdmin('B');
        $apt = $this->createTestApartment('TC03');

        $user = User::create([
            'username' => 'u_tc03_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'u_tc03_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Cư Dân TC03',
            'status' => 'ACTIVE',
        ]);

        $r1 = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'relationship_to_head' => 'CHILD',
            'occupation' => 'Developer',
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $url = "{$this->baseUrl}/residents/{$r1->id}";
        $initialUpdatedAt = (string) $r1->updated_at;

        // Admin A và Admin B cùng đọc snapshot ban đầu kèm updated_at
        $payloadA = [
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'relationship_to_head' => 'CHILD',
            'occupation' => 'Teacher',
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
            'updated_at' => $initialUpdatedAt,
        ];

        $payloadB = [
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'relationship_to_head' => 'SPOUSE',
            'occupation' => 'Developer',
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
            'updated_at' => $initialUpdatedAt,
        ];

        $results = $this->executeConcurrentHttpRequests(
            ['method' => 'PUT', 'url' => $url, 'token' => $tokenA, 'body' => $payloadA],
            ['method' => 'PUT', 'url' => $url, 'token' => $tokenB, 'body' => $payloadB]
        );

        $fresh = Resident::find($r1->id);
        $statuses = [$results['resA']['status'], $results['resB']['status']];

        echo PHP_EOL.'[TC03: FULL PAYLOAD CONFLICT]'.PHP_EOL;
        echo 'Admin A Status: '.$results['resA']['status'].', Response: '.$results['resA']['body'].PHP_EOL;
        echo 'Admin B Status: '.$results['resB']['status'].', Response: '.$results['resB']['body'].PHP_EOL;
        echo "Final DB Occupation: {$fresh->occupation}, Relationship: {$fresh->relationship_to_head}".PHP_EOL;

        $this->assertContains(200, $statuses);
        $this->assertContains(409, $statuses);
    }

    /**
     * TC04: 2 Admin cùng lúc đặt Household Head cho 2 cư dân khác nhau trong cùng 1 căn hộ
     */
    public function test_tc04_household_head_concurrency(): void
    {
        [, $tokenA] = $this->createAdmin('A');
        [, $tokenB] = $this->createAdmin('B');
        $apt = $this->createTestApartment('TC04');

        $user1 = User::create([
            'username' => 'u1_tc04_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'u1_tc04_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Cư Dân 1 TC04',
            'status' => 'ACTIVE',
        ]);

        $user2 = User::create([
            'username' => 'u2_tc04_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'u2_tc04_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Cư Dân 2 TC04',
            'status' => 'ACTIVE',
        ]);

        $r1 = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user1->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => false,
            'relationship_to_head' => 'SELF',
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $r2 = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user2->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => false,
            'relationship_to_head' => 'SELF',
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $urlA = "{$this->baseUrl}/residents/{$r1->id}";
        $urlB = "{$this->baseUrl}/residents/{$r2->id}";

        $results = $this->executeConcurrentHttpRequests(
            ['method' => 'PUT', 'url' => $urlA, 'token' => $tokenA, 'body' => ['is_head_of_household' => true]],
            ['method' => 'PUT', 'url' => $urlB, 'token' => $tokenB, 'body' => ['is_head_of_household' => true]]
        );

        $activeHeadsCount = Resident::where('apartment_id', $apt->id)
            ->where('is_head_of_household', 1)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->count();

        echo PHP_EOL.'[TC04: HOUSEHOLD HEAD CONCURRENCY]'.PHP_EOL;
        echo 'Admin A Status: '.$results['resA']['status'].', Admin B Status: '.$results['resB']['status'].PHP_EOL;
        echo 'Active Heads Count: '.$activeHeadsCount.PHP_EOL;

        $this->assertEquals(1, $activeHeadsCount, 'Tuyệt đối chỉ có duy nhất 1 Chủ hộ active');
    }

    /**
     * TC05: 2 Admin cùng DELETE (Soft Delete) CÙNG MỘT resident đồng thời
     */
    public function test_tc05_concurrent_double_delete(): void
    {
        [, $tokenA] = $this->createAdmin('A');
        [, $tokenB] = $this->createAdmin('B');
        $apt = $this->createTestApartment('TC05');

        $user = User::create([
            'username' => 'u_tc05_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'u_tc05_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Cư Dân TC05',
            'status' => 'ACTIVE',
        ]);

        $r3 = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $url = "{$this->baseUrl}/residents/{$r3->id}";

        $results = $this->executeConcurrentHttpRequests(
            ['method' => 'DELETE', 'url' => $url, 'token' => $tokenA],
            ['method' => 'DELETE', 'url' => $url, 'token' => $tokenB]
        );

        $rawRecord = DB::table('residents')->where('id', $r3->id)->first();
        $statuses = [$results['resA']['status'], $results['resB']['status']];

        echo PHP_EOL.'[TC05: DOUBLE DELETE CONCURRENT]'.PHP_EOL;
        echo 'Admin A Status: '.$results['resA']['status'].', Response: '.$results['resA']['body'].PHP_EOL;
        echo 'Admin B Status: '.$results['resB']['status'].', Response: '.$results['resB']['body'].PHP_EOL;
        echo 'Final DB Row Exists: '.($rawRecord ? 'YES' : 'NO').', deleted_at: '.$rawRecord->deleted_at.', is_active: '.$rawRecord->is_active.PHP_EOL;

        $this->assertContains(200, $statuses, 'Một request xóa thành công');
        $this->assertTrue(in_array(409, $statuses, true) || in_array(404, $statuses, true), 'Request thứ hai nhận 409 hoặc 404');
        $this->assertFalse(in_array(500, $statuses, true), 'Không bao giờ phát sinh lỗi 500');
        $this->assertNotNull($rawRecord);
        $this->assertNotNull($rawRecord->deleted_at);
        $this->assertEquals(0, $rawRecord->is_active);
    }

    /**
     * TC06: DELETE lặp lại sau khi đã xóa (Delete after Delete)
     */
    public function test_tc06_delete_after_delete(): void
    {
        [, $tokenA] = $this->createAdmin('A');
        [, $tokenB] = $this->createAdmin('B');
        $apt = $this->createTestApartment('TC06');

        $user = User::create([
            'username' => 'u_tc06_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'u_tc06_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Cư Dân TC06',
            'status' => 'ACTIVE',
        ]);

        $r3 = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $url = "{$this->baseUrl}/residents/{$r3->id}";

        // Admin A xóa trước
        $resA = $this->executeSingleHttpRequest('DELETE', $url, $tokenA);

        // Admin B xóa sau khi A đã hoàn tất
        $resB = $this->executeSingleHttpRequest('DELETE', $url, $tokenB);

        $rawRecord = DB::table('residents')->where('id', $r3->id)->first();

        echo PHP_EOL.'[TC06: DELETE AFTER DELETE]'.PHP_EOL;
        echo 'Admin A Status: '.$resA['status'].', Response: '.$resA['body'].PHP_EOL;
        echo 'Admin B Status: '.$resB['status'].', Response: '.$resB['body'].PHP_EOL;
        echo 'Final DB Row Exists: '.($rawRecord ? 'YES' : 'NO').', deleted_at: '.$rawRecord->deleted_at.PHP_EOL;

        $this->assertEquals(200, $resA['status']);
        $this->assertEquals(409, $resB['status']);
        $this->assertStringContainsString('Admin khác xóa', $resB['data']['message'] ?? '');
        $this->assertNotNull($rawRecord->deleted_at);
        $this->assertEquals(0, $rawRecord->is_active);
    }

    /**
     * TC07: Admin A UPDATE trong khi Admin B DELETE đồng thời
     */
    public function test_tc07_update_and_delete_concurrent(): void
    {
        [, $tokenA] = $this->createAdmin('A');
        [, $tokenB] = $this->createAdmin('B');
        $apt = $this->createTestApartment('TC07');

        $user = User::create([
            'username' => 'u_tc07_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'u_tc07_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Cư Dân TC07',
            'status' => 'ACTIVE',
        ]);

        $r4 = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'occupation' => 'Developer',
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $url = "{$this->baseUrl}/residents/{$r4->id}";
        $initialUpdatedAt = (string) $r4->updated_at;

        $results = $this->executeConcurrentHttpRequests(
            ['method' => 'PUT', 'url' => $url, 'token' => $tokenA, 'body' => ['occupation' => 'Teacher', 'updated_at' => $initialUpdatedAt]],
            ['method' => 'DELETE', 'url' => $url, 'token' => $tokenB]
        );

        $rawRecord = DB::table('residents')->where('id', $r4->id)->first();
        $statuses = [$results['resA']['status'], $results['resB']['status']];

        echo PHP_EOL.'[TC07: UPDATE + DELETE CONCURRENT]'.PHP_EOL;
        echo 'Admin A (Update) Status: '.$results['resA']['status'].', Response: '.$results['resA']['body'].PHP_EOL;
        echo 'Admin B (Delete) Status: '.$results['resB']['status'].', Response: '.$results['resB']['body'].PHP_EOL;
        echo "Final DB deleted_at: {$rawRecord->deleted_at}, is_active: {$rawRecord->is_active}, occupation: {$rawRecord->occupation}".PHP_EOL;

        $this->assertFalse(in_array(500, $statuses, true));
        $this->assertNotNull($rawRecord);
        $this->assertNotNull($rawRecord->deleted_at);
        $this->assertEquals(0, $rawRecord->is_active);
    }

    /**
     * TC08: Admin A DELETE trước, Admin B UPDATE sau khi đã xóa (hoặc gửi đua)
     */
    public function test_tc08_delete_then_update(): void
    {
        [, $tokenA] = $this->createAdmin('A');
        [, $tokenB] = $this->createAdmin('B');
        $apt = $this->createTestApartment('TC08');

        $user = User::create([
            'username' => 'u_tc08_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'u_tc08_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Cư Dân TC08',
            'status' => 'ACTIVE',
        ]);

        $r5 = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'TENANT',
            'is_head_of_household' => false,
            'occupation' => 'Developer',
            'stay_start_date' => '2026-01-01',
            'is_active' => true,
        ]);

        $url = "{$this->baseUrl}/residents/{$r5->id}";

        // Admin A xóa trước
        $resA = $this->executeSingleHttpRequest('DELETE', $url, $tokenA);

        // Admin B cố cập nhật sau khi A đã xóa mềm
        $resB = $this->executeSingleHttpRequest('PUT', $url, $tokenB, ['occupation' => 'Engineer']);

        $rawRecord = DB::table('residents')->where('id', $r5->id)->first();

        echo PHP_EOL.'[TC08: DELETE THEN UPDATE]'.PHP_EOL;
        echo 'Admin A (Delete) Status: '.$resA['status'].', Response: '.$resA['body'].PHP_EOL;
        echo 'Admin B (Update) Status: '.$resB['status'].', Response: '.$resB['body'].PHP_EOL;
        echo "Final DB deleted_at: {$rawRecord->deleted_at}, is_active: {$rawRecord->is_active}, occupation: {$rawRecord->occupation}".PHP_EOL;

        $this->assertEquals(200, $resA['status']);
        $this->assertEquals(409, $resB['status']);
        $this->assertStringContainsString('Cư dân đã được xóa', $resB['data']['message'] ?? '');
        $this->assertNotNull($rawRecord->deleted_at);
        $this->assertEquals(0, $rawRecord->is_active);
        $this->assertEquals('Developer', $rawRecord->occupation);
    }

    /**
     * TC09: Admin A UPDATE R6 trong khi Admin B DELETE R6 (R6 là Chủ Hộ)
     */
    public function test_tc09_delete_household_head_concurrent(): void
    {
        [, $tokenA] = $this->createAdmin('A');
        [, $tokenB] = $this->createAdmin('B');
        $apt = $this->createTestApartment('TC09');

        $user = User::create([
            'username' => 'u_tc09_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'u_tc09_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('secret'),
            'full_name' => 'Cư Dân Chủ Hộ TC09',
            'status' => 'ACTIVE',
        ]);

        $r6 = Resident::create([
            'apartment_id' => $apt->id,
            'user_id' => $user->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'relationship_to_head' => 'SELF',
            'occupation' => 'Banker',
            'stay_start_date' => '2025-01-01',
            'is_active' => true,
        ]);

        $url = "{$this->baseUrl}/residents/{$r6->id}";
        $initialUpdatedAt = (string) $r6->updated_at;

        $results = $this->executeConcurrentHttpRequests(
            ['method' => 'PUT', 'url' => $url, 'token' => $tokenA, 'body' => ['occupation' => 'CEO', 'updated_at' => $initialUpdatedAt]],
            ['method' => 'DELETE', 'url' => $url, 'token' => $tokenB]
        );

        $rawRecord = DB::table('residents')->where('id', $r6->id)->first();
        $activeHeadsCount = Resident::where('apartment_id', $apt->id)
            ->where('is_head_of_household', 1)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->count();

        echo PHP_EOL.'[TC09: DELETE HOUSEHOLD HEAD CONCURRENT]'.PHP_EOL;
        echo 'Admin A (Update) Status: '.$results['resA']['status'].', Admin B (Delete) Status: '.$results['resB']['status'].PHP_EOL;
        echo "Final DB deleted_at: {$rawRecord->deleted_at}, is_active: {$rawRecord->is_active}, is_head: {$rawRecord->is_head_of_household}".PHP_EOL;
        echo 'Active Heads Count remaining: '.$activeHeadsCount.PHP_EOL;

        $this->assertNotNull($rawRecord);
        $this->assertNotNull($rawRecord->deleted_at);
        $this->assertEquals(0, $rawRecord->is_active);
        $this->assertEquals(0, $activeHeadsCount);
    }
}
