<?php

namespace Tests\Feature;

use App\Services\AnnouncementService;
use Database\Seeders\AnnouncementPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class ResidentAnnouncementBrowserFixtureTest extends TestCase
{
    use ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('RESIDENT_ANNOUNCEMENT_BROWSER_FIXTURE')) {
            $this->markTestSkipped('Opt-in isolated resident announcement browser fixture.');
        }
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
    }

    public function test_prepare_browser_fixture(): void
    {
        $path = getenv('RESIDENT_ANNOUNCEMENT_BROWSER_FIXTURE');
        $this->assertFileDoesNotExist($path);
        $this->createBookingFixture();
        (new AnnouncementPermissionsSeeder)->run();
        $id = (string) Str::uuid();
        $title = 'QA F11 '.Str::random(10);
        app(AnnouncementService::class)->save($this->adminUser, null, ['id' => $id, 'title' => $title, 'content_html' => '<h2>Thông báo kiểm thử</h2><p>Lịch bảo trì dành cho cư dân QA.</p>', 'category' => 'MAINTENANCE', 'target_scope' => 'BLOCK', 'target_block_id' => $this->blockId, 'is_pinned' => false, 'is_urgent' => true, 'requires_acknowledgement' => true]);
        $fileId = (string) Str::uuid();
        $filePath = 'announcement-attachments/'.$id.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($filePath, "%PDF-1.4\nQA resident attachment\n%%EOF");
        DB::table('announcement_attachments')->insert(['id' => $fileId, 'announcement_id' => $id, 'file_name' => 'resident-qa.pdf', 'file_url' => $filePath, 'storage_disk' => 'local', 'mime_type' => 'application/pdf', 'file_size_bytes' => Storage::disk('local')->size($filePath)]);
        $fixture = ['id' => $id, 'title' => $title, 'admin' => $this->adminToken, 'resident' => $this->residentToken, 'second' => $this->secondToken, 'users' => [$this->residentUser->id, $this->secondUser->id, $this->adminUser->id], 'block' => $this->blockId, 'amenity' => $this->amenityId, 'category' => $this->categoryId];
        $this->assertNotFalse(file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR)));
    }

    public function test_verify_and_cleanup_browser_fixture(): void
    {
        $path = getenv('RESIDENT_ANNOUNCEMENT_BROWSER_FIXTURE');
        $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        try {
            $this->assertDatabaseHas('announcements', ['id' => $fixture['id'], 'status' => 'UNPUBLISHED', 'version' => 4]);
            $this->assertSame(2, DB::table('announcement_reads')->where('announcement_id', $fixture['id'])->whereNotNull('acknowledged_at')->count());
            $this->assertSame(4, DB::table('audit_logs')->where('record_id', $fixture['id'])->count());
        } finally {
            foreach (DB::table('announcement_attachments')->where('announcement_id', $fixture['id'])->get() as $file) {
                Storage::disk($file->storage_disk)->delete($file->file_url);
            }
            DB::table('announcement_attachments')->where('announcement_id', $fixture['id'])->delete();
            DB::table('announcement_reads')->where('announcement_id', $fixture['id'])->delete();
            DB::table('audit_logs')->where('record_id', $fixture['id'])->delete();
            DB::table('announcements')->where('id', $fixture['id'])->delete();
            DB::table('amenity_time_slots')->where('amenity_id', $fixture['amenity'])->delete();
            DB::table('amenities')->where('id', $fixture['amenity'])->delete();
            DB::table('amenity_categories')->where('id', $fixture['category'])->delete();
            foreach (['residents', 'user_sessions', 'user_roles'] as $table) {
                DB::table($table)->whereIn('user_id', $fixture['users'])->delete();
            }
            DB::table('apartments')->where('block_id', $fixture['block'])->delete();
            DB::table('floors')->where('block_id', $fixture['block'])->delete();
            DB::table('blocks')->where('id', $fixture['block'])->delete();
            DB::table('users')->whereIn('id', $fixture['users'])->delete();
            unlink($path);
        }
    }
}
