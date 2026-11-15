<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AnnouncementBrowserFixtureTest extends TestCase
{
    use ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('ANNOUNCEMENT_BROWSER_FIXTURE')) {
            $this->markTestSkipped('Opt-in browser QA fixture.');
        }
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
    }

    public function test_prepare_browser_fixture(): void
    {
        $path = getenv('ANNOUNCEMENT_BROWSER_FIXTURE');
        $this->assertFileDoesNotExist($path);
        $actor = User::factory()->create(['full_name' => 'QA Admin bảng tin']);
        $actor->assignRole(Role::where('role_code', 'SUPER_ADMIN')->firstOrFail());
        $this->assertNotFalse(file_put_contents($path, json_encode(['actor_id' => $actor->id, 'token' => $this->sessionToken($actor)], JSON_THROW_ON_ERROR)));
    }

    public function test_verify_and_cleanup_browser_fixture(): void
    {
        $path = getenv('ANNOUNCEMENT_BROWSER_FIXTURE');
        $this->assertFileExists($path);
        $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $ids = DB::table('announcements')->where('author_user_id', $fixture['actor_id'])->pluck('id');
        try {
            $this->assertCount(1, $ids);
            $row = DB::table('announcements')->where('id', $ids[0])->first();
            $this->assertNotNull($row->deleted_at);
            $this->assertSame('UNPUBLISHED', $row->status);
            $this->assertSame(6, (int) $row->version);
            $this->assertSame(6, DB::table('audit_logs')->where('record_id', $row->id)->count());
            $this->assertSame(0, DB::table('announcement_attachments')->where('announcement_id', $row->id)->count());
            $this->assertSame([], Storage::disk('local')->allFiles('announcement-attachments/'.$row->id));
        } finally {
            foreach (DB::table('announcement_attachments')->whereIn('announcement_id', $ids)->get() as $file) {
                Storage::disk('local')->delete($file->file_url);
            }
            DB::table('announcement_attachments')->whereIn('announcement_id', $ids)->delete();
            DB::table('announcement_reads')->whereIn('announcement_id', $ids)->delete();
            DB::table('audit_logs')->whereIn('record_id', $ids)->delete();
            DB::table('announcements')->whereIn('id', $ids)->delete();
            DB::table('user_sessions')->where('user_id', $fixture['actor_id'])->delete();
            DB::table('user_roles')->where('user_id', $fixture['actor_id'])->delete();
            DB::table('users')->where('id', $fixture['actor_id'])->delete();
            unlink($path);
        }
    }
}
