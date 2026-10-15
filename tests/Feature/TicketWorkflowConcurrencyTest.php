<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketCategory;
use Database\Seeders\TicketPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class TicketWorkflowConcurrencyTest extends TestCase
{
    use ResidentAmenityBookingFixtures;

    private ?string $ticketId = null;

    private ?string $ticketCategoryId = null;

    private ?string $departmentId = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('TEST_HTTP_BASE_URL')) {
            $this->markTestSkipped('Requires a dedicated multi-worker *_test HTTP server.');
        }
        $this->createBookingFixture();
        $category = TicketCategory::create(['category_code' => 'RACE-'.Str::random(12), 'category_name' => 'QA Race', 'default_priority' => 'MEDIUM']);
        $this->ticketCategoryId = $category->id;
    }

    protected function tearDown(): void
    {
        if (isset($this->amenityId)) {
            $users = [$this->residentUser->id, $this->secondUser->id, $this->adminUser->id];
            $ticketIds = DB::table('tickets')->where('category_id', $this->ticketCategoryId)->pluck('id');
            foreach ($ticketIds as $id) {
                DB::table('user_in_app_notifications')->where('category', 'TICKET')->where('deep_link_url', 'like', '%'.$id.'%')->delete();
            }
            DB::table('audit_logs')->whereIn('record_id', $ticketIds)->delete();
            DB::table('ticket_history')->whereIn('ticket_id', $ticketIds)->delete();
            DB::table('tickets')->whereIn('id', $ticketIds)->delete();
            DB::table('ticket_categories')->where('id', $this->ticketCategoryId)->delete();
            DB::table('amenity_time_slots')->where('amenity_id', $this->amenityId)->delete();
            DB::table('amenities')->where('id', $this->amenityId)->delete();
            DB::table('amenity_categories')->where('id', $this->categoryId)->delete();
            foreach (['residents', 'user_sessions', 'user_roles'] as $table) {
                DB::table($table)->whereIn('user_id', $users)->delete();
            }
            DB::table('staff_profiles')->whereIn('user_id', $users)->delete();
            if ($this->departmentId) {
                DB::table('departments')->where('id', $this->departmentId)->delete();
            }
            DB::table('apartments')->where('block_id', $this->blockId)->delete();
            DB::table('floors')->where('block_id', $this->blockId)->delete();
            DB::table('blocks')->where('id', $this->blockId)->delete();
            DB::table('users')->whereIn('id', $users)->delete();
        }
        parent::tearDown();
    }

    private function payload(): array
    {
        return ['title' => 'QA concurrent request', 'description' => 'QA repair request details', 'location' => 'Kitchen', 'category_id' => $this->ticketCategoryId, 'apartment_id' => $this->apartmentId];
    }

    private function createTicket(string $status = 'NEW'): void
    {
        $this->ticketId = $this->postJson('/api/v1/resident/tickets', $this->payload())->assertCreated()->json('data.id');
        Ticket::whereKey($this->ticketId)->update(['status' => $status]);
    }

    private function race(array $requests, string $lockTable, string $lockId): array
    {
        $multi = curl_multi_init();
        $handles = [];
        DB::beginTransaction();
        DB::table($lockTable)->where('id', $lockId)->lockForUpdate()->first();
        try {
            foreach ($requests as [$path, $token, $payload, $method]) {
                $handle = curl_init(rtrim(getenv('TEST_HTTP_BASE_URL'), '/').$path);
                curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Accept: application/json', 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40]);
                curl_multi_add_handle($multi, $handle);
                $handles[] = $handle;
            }
            curl_multi_exec($multi, $running);
            usleep(300000);
            curl_multi_exec($multi, $running);
            DB::commit();
            do {
                curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 0.1);
                }
            } while ($running);
            $statuses = [];
            foreach ($handles as $handle) {
                $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
                $this->assertContains($status, [200, 201, 409], 'Unexpected QA HTTP status '.$status);
                $statuses[] = $status;
            }
            sort($statuses);

            return $statuses;
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }
    }

    public function test_parallel_updates_keep_one_version_and_one_history_entry(): void
    {
        $this->createTicket();
        $path = '/api/v1/admin/tickets/'.$this->ticketId;
        $this->assertSame([200, 409], $this->race([[$path, $this->adminToken, ['version' => 1, 'priority' => 'HIGH'], 'PATCH'], [$path, $this->adminToken, ['version' => 1, 'priority' => 'LOW'], 'PATCH']], 'tickets', $this->ticketId));
        $this->assertSame(2, Ticket::findOrFail($this->ticketId)->version);
        $this->assertSame(1, DB::table('ticket_history')->where('ticket_id', $this->ticketId)->where('action_type', 'UPDATED')->count());
    }

    public function test_parallel_confirm_and_reopen_commit_only_one_result(): void
    {
        $this->createTicket('RESOLVED');
        $path = '/api/v1/resident/tickets/'.$this->ticketId;
        $this->assertSame([200, 409], $this->race([[$path.'/confirm', $this->residentToken, ['version' => 1], 'POST'], [$path.'/reopen', $this->residentToken, ['version' => 1, 'reason' => 'Chưa sửa xong'], 'POST']], 'tickets', $this->ticketId));
        $this->assertSame(2, Ticket::findOrFail($this->ticketId)->version);
        $this->assertSame(1, DB::table('ticket_history')->where('ticket_id', $this->ticketId)->where('action_type', 'STATUS_CHANGED')->count());
    }

    public function test_parallel_assignments_emit_one_history_and_one_technician_notification(): void
    {
        $this->createTicket('RECEIVED');
        $role = Role::firstOrCreate(['role_code' => 'TECHNICIAN'], ['role_name' => 'QA technician']);
        $this->secondUser->assignRole($role->role_code);
        (new TicketPermissionsSeeder)->run();
        $this->departmentId = (string) Str::uuid();
        DB::table('departments')->insert(['id' => $this->departmentId, 'department_code' => 'QA-'.Str::random(8), 'department_name' => 'QA Technical']);
        DB::table('staff_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->secondUser->id, 'department_id' => $this->departmentId, 'employee_code' => 'QA-'.Str::random(8), 'job_title' => 'QA Technician', 'hire_date' => today(), 'assigned_block_id' => $this->blockId]);
        $request = ['/api/v1/admin/tickets/'.$this->ticketId.'/workflow', $this->adminToken, ['version' => 1, 'action' => 'assign', 'technician_user_id' => $this->secondUser->id], 'POST'];
        $this->assertSame([200, 409], $this->race([$request, $request], 'tickets', $this->ticketId));
        $this->assertSame(1, DB::table('ticket_history')->where('ticket_id', $this->ticketId)->where('action_type', 'ASSIGNED')->count());
        $this->assertSame(1, DB::table('user_in_app_notifications')->where('recipient_user_id', $this->secondUser->id)->where('category', 'TICKET')->where('deep_link_url', 'like', '%'.$this->ticketId.'%')->count());
    }

    public function test_parallel_retries_create_one_ticket_and_one_notification(): void
    {
        $payload = [...$this->payload(), 'idempotency_key' => 'qa-concurrent-retry'];
        $request = ['/api/v1/resident/tickets', $this->residentToken, $payload, 'POST'];
        $this->assertSame([201, 201], $this->race([$request, $request], 'users', $this->residentUser->id));
        $this->assertSame(1, Ticket::where('category_id', $this->ticketCategoryId)->count());
        $id = Ticket::where('category_id', $this->ticketCategoryId)->value('id');
        $this->assertSame(1, DB::table('user_in_app_notifications')->where('recipient_user_id', $this->adminUser->id)->where('category', 'TICKET')->where('deep_link_url', 'like', '%'.$id.'%')->count());
    }
}
