<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Resident;
use App\Models\Role;
use App\Models\TemporaryRegistration;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerformanceBenchmarkTest extends TestCase
{
    protected ?User $adminUser = null;

    protected ?string $adminToken = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::create([
            'username' => 'perf_adm_'.Str::random(8),
            'phone_number' => '09'.rand(10000000, 99999999),
            'email' => 'perf_adm_'.Str::random(8).'@cassavas.vn',
            'password_hash' => Hash::make('password123'),
            'full_name' => 'Benchmark Admin',
            'status' => 'ACTIVE',
        ]);

        $role = Role::where('role_code', 'SUPER_ADMIN')->first();
        if ($role) {
            DB::table('user_roles')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $this->adminUser->id,
                'role_id' => $role->id,
                'is_primary' => 1,
                'assigned_at' => now(),
            ]);
        }

        $this->adminToken = 'smart_token_'.$this->adminUser->id.'_'.Str::random(40);
        $tokenHash = hash('sha256', $this->adminToken);

        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->adminUser->id,
            'refresh_token_hash' => $tokenHash,
            'device_name' => 'Benchmark Device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'BenchmarkAgent',
            'expires_at' => now()->addDays(1),
            'is_revoked' => 0,
            'created_at' => now(),
        ]);
    }

    private function getHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->adminToken,
            'Accept' => 'application/json',
        ];
    }

    private function measure(callable $fn): float
    {
        $start = microtime(true);
        $fn();
        $end = microtime(true);

        return round(($end - $start), 4);
    }

    public function test_run_benchmarks(): void
    {
        $results = [];

        // 1. RBAC
        $roleId = Role::value('id') ?? '';
        $r1 = $this->measure(function () {
            $this->getJson('/api/roles', $this->getHeaders());
            $this->getJson('/api/permissions', $this->getHeaders());
        });
        $r2 = $this->measure(function () {
            $this->getJson('/api/roles', $this->getHeaders());
            $this->getJson('/api/permissions', $this->getHeaders());
        });
        $r_search = $this->measure(function () {
            $this->getJson('/api/roles', $this->getHeaders());
        });
        $r_filter = $this->measure(function () {
            $this->getJson('/api/permissions?format=flat', $this->getHeaders());
        });
        $r_detail = $this->measure(function () use ($roleId) {
            if ($roleId) {
                $this->getJson("/api/roles/{$roleId}", $this->getHeaders());
            }
        });
        $results['RBAC'] = [
            'first_load' => $r1,
            'reload' => $r2,
            'search' => $r_search,
            'filter' => $r_filter,
            'detail' => $r_detail,
        ];

        // 2. Residents
        $resId = Resident::value('id') ?? '';
        $aptId = Apartment::value('id') ?? '';
        $res1 = $this->measure(function () {
            $this->getJson('/api/residents?page=1&limit=15', $this->getHeaders());
            $this->getJson('/api/residents/apartments', $this->getHeaders());
        });
        $res2 = $this->measure(function () {
            $this->getJson('/api/residents?page=1&limit=15', $this->getHeaders());
        });
        $res_search = $this->measure(function () {
            $this->getJson('/api/residents?search=Nguyen&page=1&limit=15', $this->getHeaders());
        });
        $res_filter = $this->measure(function () use ($aptId) {
            $this->getJson("/api/residents?apartment_id={$aptId}&page=1&limit=15", $this->getHeaders());
        });
        $res_detail = $this->measure(function () use ($resId) {
            if ($resId) {
                $this->getJson("/api/residents/{$resId}", $this->getHeaders());
            }
        });
        $results['Residents'] = [
            'first_load' => $res1,
            'reload' => $res2,
            'search' => $res_search,
            'filter' => $res_filter,
            'detail' => $res_detail,
        ];

        // 3. Temporary Registration
        $trId = TemporaryRegistration::value('id') ?? '';
        $tr1 = $this->measure(function () {
            $this->getJson('/api/temporary-registrations?page=1&limit=15', $this->getHeaders());
        });
        $tr2 = $this->measure(function () {
            $this->getJson('/api/temporary-registrations?page=1&limit=15', $this->getHeaders());
        });
        $tr_search = $this->measure(function () {
            $this->getJson('/api/temporary-registrations?search=test&page=1&limit=15', $this->getHeaders());
        });
        $tr_filter = $this->measure(function () {
            $this->getJson('/api/temporary-registrations?police_status=PENDING&page=1&limit=15', $this->getHeaders());
        });
        $tr_detail = $this->measure(function () use ($trId) {
            if ($trId) {
                $this->getJson("/api/temporary-registrations/{$trId}", $this->getHeaders());
            }
        });
        $results['TemporaryRegistration'] = [
            'first_load' => $tr1,
            'reload' => $tr2,
            'search' => $tr_search,
            'filter' => $tr_filter,
            'detail' => $tr_detail,
        ];

        // 4. Account Provisioning
        $userId = $this->adminUser->id;
        $acc1 = $this->measure(function () {
            $this->getJson('/api/users?roles=RESIDENT&limit=15', $this->getHeaders());
            $this->getJson('/api/roles', $this->getHeaders());
        });
        $acc2 = $this->measure(function () {
            $this->getJson('/api/users?roles=RESIDENT&limit=15', $this->getHeaders());
        });
        $acc_search = $this->measure(function () {
            $this->getJson('/api/users?search=Nguyen&limit=15', $this->getHeaders());
        });
        $acc_filter = $this->measure(function () {
            $this->getJson('/api/users?status=ACTIVE&limit=15', $this->getHeaders());
        });
        $acc_detail = $this->measure(function () use ($userId) {
            $this->getJson("/api/users/{$userId}", $this->getHeaders());
        });
        $results['AccountProvisioning'] = [
            'first_load' => $acc1,
            'reload' => $acc2,
            'search' => $acc_search,
            'filter' => $acc_filter,
            'detail' => $acc_detail,
        ];

        // 5. Vehicles
        $vehId = Vehicle::value('id') ?? '';
        $veh1 = $this->measure(function () {
            $this->getJson('/api/vehicles?page=1&per_page=15', $this->getHeaders());
            $this->getJson('/api/vehicles/apartments', $this->getHeaders());
            $this->getJson('/api/vehicles/pricing-configs', $this->getHeaders());
        });
        $veh2 = $this->measure(function () {
            $this->getJson('/api/vehicles?page=1&per_page=15', $this->getHeaders());
        });
        $veh_search = $this->measure(function () {
            $this->getJson('/api/vehicles?search=29&page=1&per_page=15', $this->getHeaders());
        });
        $veh_filter = $this->measure(function () {
            $this->getJson('/api/vehicles?vehicle_category=MOTORBIKE&page=1&per_page=15', $this->getHeaders());
        });
        $veh_detail = $this->measure(function () use ($vehId) {
            if ($vehId) {
                $this->getJson("/api/vehicles/{$vehId}", $this->getHeaders());
            }
        });
        $results['Vehicles'] = [
            'first_load' => $veh1,
            'reload' => $veh2,
            'search' => $veh_search,
            'filter' => $veh_filter,
            'detail' => $veh_detail,
        ];

        echo "\nBENCHMARK_RESULTS_JSON:".json_encode($results)."\n";
        $this->assertTrue(true);
    }
}
