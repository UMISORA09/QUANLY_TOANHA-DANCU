<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TicketCategory;
use Database\Seeders\TicketPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class TicketBrowserFixtureTest extends TestCase
{
    use ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('TICKET_BROWSER_FIXTURE')) {
            $this->markTestSkipped('Opt-in real browser QA fixture.');
        }
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
    }

    public function test_prepare_browser_fixture(): void
    {
        $path = getenv('TICKET_BROWSER_FIXTURE');
        $this->assertFileDoesNotExist($path);
        $this->createBookingFixture();
        $this->residentUser->update(['full_name' => 'Cư dân Ticket Browser QA']);
        $this->secondUser->update(['full_name' => 'Kỹ thuật Ticket Browser QA']);
        $category = TicketCategory::create(['category_code' => 'BROWSER-'.Str::random(8), 'category_name' => 'Browser QA Điện nước', 'default_priority' => 'HIGH']);
        Role::firstOrCreate(['role_code' => 'TECHNICIAN'], ['role_name' => 'Kỹ thuật viên']);
        $this->secondUser->assignRole('TECHNICIAN');
        (new TicketPermissionsSeeder)->run();
        $department = (string) Str::uuid();
        DB::table('departments')->insert(['id' => $department, 'department_code' => 'BROWSER-'.Str::random(8), 'department_name' => 'Browser QA Technical']);
        DB::table('staff_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->secondUser->id, 'employee_code' => 'BROWSER-'.Str::random(8), 'department_id' => $department, 'job_title' => 'QA Technician', 'hire_date' => today(), 'assigned_block_id' => $this->blockId]);
        $this->assertNotFalse(file_put_contents($path, json_encode(['resident_token' => $this->residentToken, 'admin_token' => $this->adminToken, 'technician_token' => $this->secondToken,
            'user_ids' => [$this->residentUser->id, $this->secondUser->id, $this->adminUser->id], 'block_id' => $this->blockId, 'amenity_id' => $this->amenityId, 'amenity_category_id' => $this->categoryId, 'ticket_category_id' => $category->id, 'department_id' => $department], JSON_THROW_ON_ERROR)));
    }

    public function test_verify_and_cleanup_browser_fixture(): void
    {
        $path = getenv('TICKET_BROWSER_FIXTURE');
        $this->assertFileExists($path);
        $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $ids = DB::table('tickets')->where('category_id', $fixture['ticket_category_id'])->pluck('id');
        try {
            $this->assertCount(1, $ids);
            $ticket = DB::table('tickets')->where('id', $ids[0])->first();
            $this->assertSame('CLOSED', $ticket->status);
            $this->assertSame(9, (int) $ticket->version);
            $this->assertSame(2, DB::table('ticket_attachments')->where('ticket_id', $ticket->id)->count());
            $comments = DB::table('ticket_comments')->where('ticket_id', $ticket->id)->get();
            $this->assertCount(4, $comments);
            $this->assertCount(1, $comments->where('is_internal_staff_note', true));
            $this->assertSame(4, DB::table('audit_logs')->where('table_name', 'ticket_comments')->whereIn('record_id', $comments->pluck('id'))->count());
            $this->assertSame(1, DB::table('ticket_history')->where('ticket_id', $ticket->id)->where('new_status', 'CLOSED')->count());
            $this->assertGreaterThan(0, DB::table('user_in_app_notifications')->whereIn('recipient_user_id', $fixture['user_ids'])->where('category', 'TICKET')->count());
        } finally {
            foreach ($ids as $id) {
                foreach (DB::table('ticket_attachments')->where('ticket_id', $id)->get() as $attachment) {
                    Storage::disk($attachment->storage_disk)->delete($attachment->storage_path);
                }
                DB::table('user_in_app_notifications')->where('category', 'TICKET')->where('deep_link_url', 'like', '%'.$id.'%')->delete();
            }
            $commentIds = DB::table('ticket_comments')->whereIn('ticket_id', $ids)->pluck('id');
            DB::table('audit_logs')->whereIn('record_id', $commentIds)->delete();
            DB::table('ticket_comments')->whereIn('ticket_id', $ids)->delete();
            DB::table('ticket_attachments')->whereIn('ticket_id', $ids)->delete();
            DB::table('ticket_history')->whereIn('ticket_id', $ids)->delete();
            DB::table('audit_logs')->whereIn('record_id', $ids)->delete();
            DB::table('tickets')->whereIn('id', $ids)->delete();
            DB::table('ticket_categories')->where('id', $fixture['ticket_category_id'])->delete();
            DB::table('staff_profiles')->whereIn('user_id', $fixture['user_ids'])->delete();
            DB::table('departments')->where('id', $fixture['department_id'])->delete();
            foreach (['residents', 'user_sessions', 'user_roles'] as $table) {
                DB::table($table)->whereIn('user_id', $fixture['user_ids'])->delete();
            }
            DB::table('amenity_time_slots')->where('amenity_id', $fixture['amenity_id'])->delete();
            DB::table('amenities')->where('id', $fixture['amenity_id'])->delete();
            DB::table('amenity_categories')->where('id', $fixture['amenity_category_id'])->delete();
            DB::table('apartments')->where('block_id', $fixture['block_id'])->delete();
            DB::table('floors')->where('block_id', $fixture['block_id'])->delete();
            DB::table('blocks')->where('id', $fixture['block_id'])->delete();
            DB::table('users')->whereIn('id', $fixture['user_ids'])->delete();
            unlink($path);
        }
    }
}
