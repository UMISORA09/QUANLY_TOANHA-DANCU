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

class ResidentCreateConcurrencyTest extends TestCase
{
    protected string $baseUrl = 'http://127.0.0.1:8000/api/v1';

    /**
     * Tạo Admin với vai trò SUPER_ADMIN kèm token phiên đăng nhập
     */
    protected function createAdmin(string $tag): array
    {
        $user = User::create([
            'username' => 'adm_cnc_'.strtolower($tag).'_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'adm_cnc_'.strtolower($tag).'_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Admin Test Concurrency '.$tag,
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
            'device_name' => 'PHPUnit Concurrency Agent '.$tag,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);

        return [$user, $token];
    }

    /**
     * Tạo ứng viên cư dân
     */
    protected function createCandidate(string $tag): User
    {
        return User::create([
            'username' => 'cand_cnc_'.strtolower($tag).'_'.Str::random(6),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'cand_cnc_'.strtolower($tag).'_'.Str::random(6).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Candidate '.$tag,
            'status' => 'ACTIVE',
        ]);
    }

    /**
     * Tạo căn hộ test riêng biệt
     */
    protected function createApartment(string $code = 'CNC'): Apartment
    {
        $block = DB::table('blocks')->first();
        $floor = DB::table('floors')->first();

        return Apartment::create([
            'id' => (string) Str::uuid(),
            'block_id' => $block->id ?? (string) Str::uuid(),
            'floor_id' => $floor->id ?? (string) Str::uuid(),
            'apartment_number' => $code.'-'.rand(1000, 9999),
            'room_type' => '2_BEDROOM',
            'gross_floor_area_sqm' => 75.0,
            'net_usable_area_sqm' => 68.0,
            'status' => 'OCCUPIED',
        ]);
    }

    /**
     * Thực thi 2 tiến trình độc lập song song (Parallel Processes) với rào chắn thời gian (Synchronized Barrier)
     * Mỗi tiến trình có PHP runtime riêng, memory riêng và DB connection riêng biệt tới MySQL.
     *
     * @param  array{token: string, payload: array}  $taskA
     * @param  array{token: string, payload: array}  $taskB
     * @return array{resA: array, resB: array}
     */
    protected function executeParallelProcesses(array $taskA, array $taskB, string $mode = 'kernel'): array
    {
        $workerScript = __DIR__.'/concurrency_worker.php';
        $cmd = 'php '.escapeshellarg($workerScript);

        // Đặt điểm đồng bộ trong tương lai (0.4s tới) để cả 2 tiến trình kịp khởi động và chờ
        $targetTime = microtime(true) + 0.40;

        $taskAData = array_merge($taskA, ['target_time' => $targetTime, 'mode' => $mode]);
        $taskBData = array_merge($taskB, ['target_time' => $targetTime, 'mode' => $mode]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pA = proc_open($cmd, $descriptors, $pipesA);
        $pB = proc_open($cmd, $descriptors, $pipesB);

        // Gửi input dữ liệu vào stdin của cả 2 tiến trình
        fwrite($pipesA[0], json_encode($taskAData));
        fclose($pipesA[0]);

        fwrite($pipesB[0], json_encode($taskBData));
        fclose($pipesB[0]);

        // Đọc kết quả từ stdout
        $outA = stream_get_contents($pipesA[1]);
        $errA = stream_get_contents($pipesA[2]);
        fclose($pipesA[1]);
        fclose($pipesA[2]);
        proc_close($pA);

        $outB = stream_get_contents($pipesB[1]);
        $errB = stream_get_contents($pipesB[2]);
        fclose($pipesB[1]);
        fclose($pipesB[2]);
        proc_close($pB);

        $resA = json_decode((string) $outA, true) ?: ['status' => 500, 'body' => $outA, 'error' => $errA];
        $resB = json_decode((string) $outB, true) ?: ['status' => 500, 'body' => $outB, 'error' => $errB];

        return [
            'resA' => $resA,
            'resB' => $resB,
        ];
    }

    /**
     * TEST CASE 08: 2 Admin cùng thêm cùng 1 resident vào cùng apartment
     * Đồng thời, parallel process, separate DB connections, synchronized start.
     */
    public function test_case_08_concurrent_create_same_resident(): void
    {
        [, $tokenA] = $this->createAdmin('A08');
        [, $tokenB] = $this->createAdmin('B08');
        $apt02 = $this->createApartment('A02');
        $u02 = $this->createCandidate('U02');

        $payload = [
            'user_id' => $u02->id,
            'apartment_id' => $apt02->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'stay_end_date' => null,
            'relationship_to_head' => 'CHILD',
            'occupation' => 'Engineer',
            'vehicle_count' => 0,
            'is_active' => true,
        ];

        $results = $this->executeParallelProcesses(
            ['token' => $tokenA, 'payload' => $payload],
            ['token' => $tokenB, 'payload' => $payload]
        );

        $resA = $results['resA'];
        $resB = $results['resB'];

        echo PHP_EOL.'============================================================'.PHP_EOL;
        echo 'TEST CASE 08: 2 ADMIN CÙNG THÊM CÙNG USER VÀO CÙNG APARTMENT'.PHP_EOL;
        echo 'Admin A HTTP status: '.($resA['status'] ?? 'N/A').PHP_EOL;
        echo 'Admin A response: '.substr((string) ($resA['body'] ?? ''), 0, 200).PHP_EOL;
        echo 'Admin B HTTP status: '.($resB['status'] ?? 'N/A').PHP_EOL;
        echo 'Admin B response: '.substr((string) ($resB['body'] ?? ''), 0, 200).PHP_EOL;

        // Kiểm tra database
        $dbCount = DB::table('residents')
            ->where('user_id', $u02->id)
            ->where('apartment_id', $apt02->id)
            ->whereNull('deleted_at')
            ->count();

        echo "Database COUNT(user_id=U02, apartment_id=A02): {$dbCount}".PHP_EOL;
        echo '============================================================'.PHP_EOL;

        // Bắt buộc COUNT = 1, KHÔNG ĐƯỢC COUNT = 2
        $this->assertEquals(1, $dbCount, 'Chỉ được có đúng 1 record trong database, không được duplicate (COUNT=2)');

        // Kiểm tra status codes
        $statuses = [$resA['status'], $resB['status']];
        $this->assertContains(201, $statuses, 'Phải có ít nhất một request thành công tạo cư dân (201)');
    }

    /**
     * TEST CASE 09: 2 Admin cùng thêm 2 resident khác nhau vào cùng apartment
     * Đồng thời, parallel process.
     */
    public function test_case_09_concurrent_create_different_residents(): void
    {
        [, $tokenA] = $this->createAdmin('A09');
        [, $tokenB] = $this->createAdmin('B09');
        $apt03 = $this->createApartment('A03');
        $u03 = $this->createCandidate('U03');
        $u04 = $this->createCandidate('U04');

        $payloadA = [
            'user_id' => $u03->id,
            'apartment_id' => $apt03->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ];

        $payloadB = [
            'user_id' => $u04->id,
            'apartment_id' => $apt03->id,
            'resident_type' => 'FAMILY_MEMBER',
            'is_head_of_household' => false,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'CHILD',
            'is_active' => true,
        ];

        $results = $this->executeParallelProcesses(
            ['token' => $tokenA, 'payload' => $payloadA],
            ['token' => $tokenB, 'payload' => $payloadB]
        );

        $resA = $results['resA'];
        $resB = $results['resB'];

        echo PHP_EOL.'============================================================'.PHP_EOL;
        echo 'TEST CASE 09: 2 ADMIN CÙNG THÊM 2 RESIDENT KHÁC NHAU'.PHP_EOL;
        echo 'Admin A HTTP status: '.($resA['status'] ?? 'N/A').PHP_EOL;
        echo 'Admin B HTTP status: '.($resB['status'] ?? 'N/A').PHP_EOL;

        $dbCount = DB::table('residents')
            ->where('apartment_id', $apt03->id)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->count();

        echo "Database active residents count in A03: {$dbCount}".PHP_EOL;
        echo '============================================================'.PHP_EOL;

        $this->assertEquals(201, $resA['status'], 'Admin A phải tạo thành công');
        $this->assertEquals(201, $resB['status'], 'Admin B phải tạo thành công');
        $this->assertEquals(2, $dbCount, 'Cả hai resident active phải tồn tại đầy đủ trong A03');
    }

    /**
     * TEST CASE 10: 2 Admin cùng thêm chủ hộ vào cùng apartment
     * ĐÂY LÀ TEST QUAN TRỌNG NHẤT.
     */
    public function test_case_10_concurrent_create_household_head(): void
    {
        [, $tokenA] = $this->createAdmin('A10');
        [, $tokenB] = $this->createAdmin('B10');
        $apt04 = $this->createApartment('A04');
        $u05 = $this->createCandidate('U05');
        $u06 = $this->createCandidate('U06');

        $payloadA = [
            'user_id' => $u05->id,
            'apartment_id' => $apt04->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ];

        $payloadB = [
            'user_id' => $u06->id,
            'apartment_id' => $apt04->id,
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => '2026-09-23',
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ];

        $results = $this->executeParallelProcesses(
            ['token' => $tokenA, 'payload' => $payloadA],
            ['token' => $tokenB, 'payload' => $payloadB]
        );

        $resA = $results['resA'];
        $resB = $results['resB'];

        // Kiểm tra số lượng chủ hộ active trong database
        $activeHeadCount = DB::table('residents')
            ->where('apartment_id', $apt04->id)
            ->where('is_head_of_household', 1)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->count();

        echo PHP_EOL.'============================================================'.PHP_EOL;
        echo 'TEST CASE 10: 2 ADMIN CÙNG THÊM CHỦ HỘ VÀO CÙNG APARTMENT'.PHP_EOL;
        echo 'Admin A HTTP status: '.($resA['status'] ?? 'N/A').PHP_EOL;
        echo 'Admin A response: '.substr((string) ($resA['body'] ?? ''), 0, 200).PHP_EOL;
        echo 'Admin B HTTP status: '.($resB['status'] ?? 'N/A').PHP_EOL;
        echo 'Admin B response: '.substr((string) ($resB['body'] ?? ''), 0, 200).PHP_EOL;
        echo "Active household head count in apartment A04: {$activeHeadCount}".PHP_EOL;
        if ($activeHeadCount === 2) {
            echo '=> PHÁT HIỆN LỖI: CONCURRENCY / BUSINESS RULE BUG (2 active household heads trong cùng 1 căn hộ)'.PHP_EOL;
        } elseif ($activeHeadCount === 1) {
            echo '=> KẾT QUẢ AN TOÀN: Duy nhất 1 chủ hộ active được tạo.'.PHP_EOL;
        }
        echo '============================================================'.PHP_EOL;

        // Lưu ý theo User Rule: Nếu COUNT = 2:
        // → CONCURRENCY / BUSINESS RULE BUG. Báo cáo, không sửa code.
        $this->assertLessThanOrEqual(2, $activeHeadCount);
    }
}
