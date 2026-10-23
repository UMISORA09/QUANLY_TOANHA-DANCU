<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\AnnouncementPermissionsSeeder;
use Database\Seeders\AnnouncementSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AnnouncementManagementTest extends TestCase
{
    use DatabaseTransactions;
    use ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        config(['announcements.attachment_disk' => 'local']);
        Storage::fake('local');
        (new AnnouncementPermissionsSeeder)->run();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
    }

    private function url(?string $id = null): string
    {
        return '/api/v1/admin/announcements'.($id ? '/'.$id : '');
    }

    private function payload(): array
    {
        return ['id' => (string) Str::uuid(), 'title' => 'QA bảo trì thang máy', 'summary_excerpt' => 'Thông báo QA', 'content_html' => '<h2>Lịch bảo trì</h2><p>Vui lòng sử dụng thang còn hoạt động.</p>', 'category' => 'MAINTENANCE', 'target_scope' => 'BLOCK', 'target_block_id' => $this->blockId, 'is_pinned' => true, 'is_urgent' => false];
    }

    private function create(array $extra = []): array
    {
        return $this->postJson($this->url(), [...$this->payload(), ...$extra])->assertCreated()->json();
    }

    private function manager(?string $block, bool $profile = true): User
    {
        $user = User::factory()->create();
        if ($block && ! DB::table('blocks')->where('id', $block)->exists()) {
            DB::table('blocks')->insert(['id' => $block, 'block_code' => 'QA-'.Str::random(8), 'block_name' => 'QA Outside']);
        }
        Role::firstOrCreate(['role_code' => 'BUILDING_MANAGER'], ['role_name' => 'QA Quản lý']);
        $user->assignRole('BUILDING_MANAGER');
        (new AnnouncementPermissionsSeeder)->run();
        if ($profile) {
            $department = (string) Str::uuid();
            DB::table('departments')->insert(['id' => $department, 'department_code' => 'QA-'.Str::random(8), 'department_name' => 'QA Bảng tin']);
            DB::table('staff_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'department_id' => $department, 'employee_code' => 'QA-'.Str::random(8), 'job_title' => 'QA Manager', 'hire_date' => today(), 'assigned_block_id' => $block]);
        }

        return $user;
    }

    public function test_strict_auth_and_resident_and_technician_are_rejected(): void
    {
        $this->withHeader('Authorization', '')->getJson($this->url())->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer smart_token_admin_demo')->postJson($this->url(), $this->payload())->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->getJson($this->url())->assertForbidden();
        Role::firstOrCreate(['role_code' => 'TECHNICIAN'], ['role_name' => 'QA Tech']);
        $this->secondUser->assignRole('TECHNICIAN');
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken)->postJson($this->url(), $this->payload())->assertForbidden();
        DB::table('user_sessions')->where('user_id', $this->adminUser->id)->update(['expires_at' => now()->subMinute()]);
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)->getJson($this->url())->assertUnauthorized();
    }

    public function test_save_sanitizes_html_and_preview_uses_same_filter(): void
    {
        $html = '<h2 onclick="alert(1)">Tiêu đề</h2><script>alert(1)</script><style>body{display:none}</style><svg onload="alert(1)"></svg><p style="color:white">Xin chào <u>cư dân</u><a href="javascript:alert(1)">Xấu</a><a href="https://example.test" onclick="alert(1)">Tốt</a><img src="https://evil.test/x"></p>';
        $row = $this->create(['content_html' => $html]);
        $this->assertSame('DRAFT', $row['status']);
        $this->assertSame($this->adminUser->id, $row['author_user_id']);
        foreach (['script', 'style=', 'onclick', '<svg', '<img', 'javascript:', 'alert(1)'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $row['content_html']);
        }
        $this->assertStringContainsString('noopener noreferrer', $row['content_html']);
        $this->assertStringContainsString('<u>', $row['content_html']);
        $this->postJson($this->url().'/preview', ['content_html' => $html])->assertOk()->assertJsonPath('content_html', $row['content_html']);
        $this->getJson($this->url($row['id']))->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_validation_empty_oversized_scope_floor_and_actor_spoof(): void
    {
        $this->postJson($this->url(), [...$this->payload(), 'content_html' => '<script>alert(1)</script><p>&nbsp;</p>'])->assertUnprocessable()->assertJsonValidationErrors('content_html');
        $this->postJson($this->url(), [...$this->payload(), 'content_html' => '<p>'.str_repeat('á', 31000).'</p>'])->assertUnprocessable()->assertJsonValidationErrors('content_html');
        $this->postJson($this->url(), [...$this->payload(), 'title' => str_repeat('x', 256)])->assertUnprocessable();
        $this->postJson($this->url(), [...$this->payload(), 'author_user_id' => $this->residentUser->id, 'status' => 'PUBLISHED'])->assertUnprocessable();
        $floor = DB::table('floors')->where('block_id', $this->blockId)->value('id');
        $row = $this->create(['target_scope' => 'FLOOR', 'target_floor_id' => $floor]);
        $this->assertSame($floor, $row['target_floor_id']);
        $this->postJson($this->url(), [...$this->payload(), 'target_scope' => 'FLOOR', 'target_floor_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_draft_schedule_expiry_unpublish_republish_and_archive(): void
    {
        $row = $this->create(['publish_at' => now()->addDay()->toIso8601String(), 'expires_at' => now()->addDays(2)->toIso8601String()]);
        $this->postJson($this->url($row['id']).'/publish', ['version' => 1, 'publish_at' => now()->addDay()->toIso8601String()])->assertOk()->assertJsonPath('display_status', 'SCHEDULED');
        $this->travel(25)->hours();
        $this->getJson($this->url($row['id']))->assertJsonPath('display_status', 'PUBLISHED');
        $this->travel(25)->hours();
        $this->getJson($this->url($row['id']))->assertJsonPath('display_status', 'EXPIRED');
        $this->postJson($this->url($row['id']).'/unpublish', ['version' => 2, 'reason' => 'Gỡ lịch bảo trì đã hết hạn'])->assertOk()->assertJsonPath('status', 'UNPUBLISHED');
        $this->postJson($this->url($row['id']).'/publish', ['version' => 3])->assertUnprocessable();
        $this->deleteJson($this->url($row['id']), ['version' => 3, 'reason' => 'Lưu trữ lịch bảo trì cũ'])->assertOk();
        $this->getJson($this->url($row['id']))->assertNotFound();
        $this->assertDatabaseHas('announcements', ['id' => $row['id'], 'version' => 4]);
        $this->assertNotNull(DB::table('announcements')->where('id', $row['id'])->value('deleted_at'));
        $this->assertSame(4, DB::table('audit_logs')->where('record_id', $row['id'])->count());
        $this->assertSame(0, DB::table('user_in_app_notifications')->where('deep_link_url', 'like', '%'.$row['id'].'%')->count());
    }

    public function test_stale_versions_and_retry_do_not_duplicate_or_overwrite(): void
    {
        $payload = $this->payload();
        $row = $this->postJson($this->url(), $payload)->assertCreated()->json();
        $this->postJson($this->url(), $payload)->assertConflict();
        unset($payload['id']);
        $this->patchJson($this->url($row['id']), [...$payload, 'version' => 1, 'title' => 'QA đã sửa'])->assertOk()->assertJsonPath('version', 2);
        $this->patchJson($this->url($row['id']), [...$payload, 'version' => 1, 'title' => 'Không được ghi đè'])->assertConflict();
        $this->postJson($this->url($row['id']).'/publish', ['version' => 1])->assertConflict();
        $this->assertDatabaseHas('announcements', ['id' => $row['id'], 'title' => 'QA đã sửa', 'version' => 2]);
        $this->assertSame(2, DB::table('audit_logs')->where('record_id', $row['id'])->count());
    }

    public function test_manager_scope_global_read_only_missing_profile_and_permissions(): void
    {
        $local = $this->create();
        $global = $this->create(['target_scope' => 'ALL_BLOCKS']);
        $manager = $this->manager($this->blockId);
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($manager));
        $this->getJson($this->url($local['id']))->assertOk()->assertJsonPath('actions.edit', true);
        $this->getJson($this->url($global['id']))->assertOk()->assertJsonPath('actions.edit', false);
        $this->postJson($this->url($global['id']).'/publish', ['version' => 1])->assertForbidden();
        $this->postJson($this->url(), [...$this->payload(), 'target_scope' => 'ALL_BLOCKS'])->assertForbidden();
        $outside = $this->manager((string) Str::uuid());
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($outside))->getJson($this->url($local['id']))->assertNotFound();
        $missing = $this->manager(null, false);
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($missing))->getJson($this->url())->assertForbidden();
        $all = $this->manager(null);
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($all))->getJson($this->url($global['id']))->assertOk()->assertJsonPath('actions.edit', true);
        DB::table('role_permissions')->whereIn('role_id', $all->roles()->pluck('roles.id'))->where('permission_id', DB::table('permissions')->where('permission_code', 'ANNOUNCEMENT:PUBLISH')->value('id'))->delete();
        $this->postJson($this->url($local['id']).'/publish', ['version' => 1])->assertForbidden();
    }

    public function test_private_attachment_validation_ownership_and_archive_retention(): void
    {
        $payload = $this->payload();
        $row = $this->post($this->url(), [...$payload, 'attachments' => [UploadedFile::fake()->image('photo.png')]], ['Accept' => 'application/json'])->assertCreated()->json();
        $attachment = $row['attachments'][0];
        $this->assertArrayNotHasKey('file_url', $attachment);
        $path = DB::table('announcement_attachments')->where('id', $attachment['id'])->value('file_url');
        Storage::disk('local')->assertExists($path);
        $this->get($this->url($row['id']).'/attachments/'.$attachment['id'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = $this->create();
        $this->getJson($this->url($other['id']).'/attachments/'.$attachment['id'])->assertNotFound();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken)->getJson($this->url($row['id']).'/attachments/'.$attachment['id'])->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)->deleteJson($this->url($row['id']), ['version' => 1, 'reason' => 'Giữ tệp khi lưu trữ bài'])->assertOk();
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('announcement_attachments', ['id' => $attachment['id']]);
        $this->getJson($this->url($row['id']).'/attachments/'.$attachment['id'])->assertNotFound();
    }

    public function test_fake_mime_limits_pdf_and_invalid_remove_leave_no_partial_data(): void
    {
        $this->post($this->url(), [...$this->payload(), 'attachments' => [UploadedFile::fake()->createWithContent('fake.png', '<script>bad</script>')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($this->url(), [...$this->payload(), 'attachments' => array_fill(0, 6, UploadedFile::fake()->image('a.png'))], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($this->url(), [...$this->payload(), 'attachments' => [UploadedFile::fake()->create('a.pdf', 5121, 'application/pdf')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $row = $this->post($this->url(), [...$this->payload(), 'attachments' => [UploadedFile::fake()->createWithContent('rules.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF")]], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertSame('application/pdf', $row['attachments'][0]['mime_type']);
        $data = $this->payload();
        unset($data['id']);
        $this->patchJson($this->url($row['id']), [...$data, 'version' => 1, 'remove_attachment_ids' => [(string) Str::uuid()]])->assertUnprocessable();
        $this->assertDatabaseHas('announcements', ['id' => $row['id'], 'version' => 1]);
    }

    public function test_audit_failure_rolls_back_new_files_without_deleting_existing_files(): void
    {
        $old = 'announcement-attachments/old.txt';
        Storage::disk('local')->put($old, 'Keep QA');
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into `audit_logs`')) {
                throw new \RuntimeException('Injected QA audit failure');
            }
        });
        $payload = $this->payload();
        $this->post($this->url(), [...$payload, 'attachments' => [UploadedFile::fake()->image('a.png')]], ['Accept' => 'application/json'])->assertServerError();
        $this->assertDatabaseMissing('announcements', ['id' => $payload['id']]);
        $this->assertSame([$old], Storage::disk('local')->allFiles());
    }

    public function test_production_storage_fails_without_private_configuration(): void
    {
        $this->app->instance('env', 'production');
        try {
            $this->post($this->url(), [...$this->payload(), 'attachments' => [UploadedFile::fake()->image('a.png')]], ['Accept' => 'application/json'])->assertStatus(503);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_stable_pagination_and_filters(): void
    {
        $ids = [];
        for ($i = 0; $i < 32; $i++) {
            $row = $this->create(['title' => 'QA pagination '.$i]);
            $ids[] = $row['id'];
        }
        $first = $this->getJson($this->url().'?search=QA%20pagination&status=DRAFT')->assertOk()->json();
        $second = $this->getJson($this->url().'?search=QA%20pagination&status=DRAFT&page=2')->assertOk()->json();
        $third = $this->getJson($this->url().'?search=QA%20pagination&status=DRAFT&page=3')->assertOk()->json();
        $all = array_merge(array_column($first['data'], 'id'), array_column($second['data'], 'id'), array_column($third['data'], 'id'));
        $this->assertCount(15, $first['data']);
        $this->assertCount(15, $second['data']);
        $this->assertCount(2, $third['data']);
        $this->assertEqualsCanonicalizing($ids, $all);
        $this->assertCount(32, array_unique($all));
        $this->assertArrayNotHasKey('content_html', $first['data'][0]);
    }

    public function test_sample_and_permission_seeders_are_idempotent_and_block_production(): void
    {
        $this->seed(AnnouncementPermissionsSeeder::class);
        $count = DB::table('role_permissions')->count();
        $this->seed(AnnouncementPermissionsSeeder::class);
        $this->assertSame($count, DB::table('role_permissions')->count());
        $this->seed(AnnouncementSeeder::class);
        $count = DB::table('announcements')->count();
        $audits = DB::table('audit_logs')->count();
        $this->seed(AnnouncementSeeder::class);
        $this->assertSame($count, DB::table('announcements')->count());
        $this->assertSame($audits, DB::table('audit_logs')->count());
        $this->app->instance('env', 'production');
        try {
            $this->seed(AnnouncementSeeder::class);
            $this->assertSame($count, DB::table('announcements')->count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_storage_failure_rolls_back_article_and_attempts_partial_file_cleanup(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        $disk->shouldReceive('delete')->once()->withArgs(fn (string $path): bool => str_starts_with($path, 'announcement-attachments/'))->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $payload = $this->payload();
        $this->post($this->url(), [...$payload, 'attachments' => [UploadedFile::fake()->image('a.png')]], ['Accept' => 'application/json'])->assertServerError();
        $this->assertDatabaseMissing('announcements', ['id' => $payload['id']]);
        $this->assertDatabaseMissing('audit_logs', ['record_id' => $payload['id']]);
    }

    public function test_failed_replacement_preserves_existing_attachment_and_version(): void
    {
        $row = $this->post($this->url(), [...$this->payload(), 'attachments' => [UploadedFile::fake()->image('old.png')]], ['Accept' => 'application/json'])->assertCreated()->json();
        $file = DB::table('announcement_attachments')->where('announcement_id', $row['id'])->first();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into `audit_logs`')) {
                throw new \RuntimeException('Injected replacement failure');
            }
        });
        $data = $this->payload();
        unset($data['id']);
        $this->post($this->url($row['id']), [...$data, '_method' => 'PATCH', 'version' => 1, 'remove_attachment_ids' => [$file->id], 'attachments' => [UploadedFile::fake()->image('new.png')]], ['Accept' => 'application/json'])->assertServerError();
        $this->assertDatabaseHas('announcements', ['id' => $row['id'], 'version' => 1]);
        $this->assertDatabaseHas('announcement_attachments', ['id' => $file->id]);
        Storage::disk('local')->assertExists($file->file_url);
        $this->assertSame([$file->file_url], Storage::disk('local')->allFiles());
    }

    public function test_direct_upload_preserves_five_mb_and_checks_owner_post_expiry_and_mime(): void
    {
        $payload = $this->payload();
        $bytes = '%PDF-1.4'.str_repeat(' ', 5 * 1024 * 1024 - 8);
        $ticket = $this->postJson($this->url().'/uploads', ['announcement_id' => $payload['id'], 'file_name' => 'large.pdf', 'size' => strlen($bytes), 'mime_type' => 'application/pdf'])->assertOk()->json();
        $this->assertSame('local', $ticket['transport']);
        $this->call('PUT', $ticket['url'], [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken], $bytes)->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken)->postJson($this->url().'/uploads/discard', ['token' => $ticket['token']])->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken);
        $this->postJson($this->url(), [...$this->payload(), 'uploaded_attachment_tokens' => [$ticket['token']]])->assertUnprocessable();
        $result = $this->postJson($this->url(), [...$payload, 'uploaded_attachment_tokens' => [$ticket['token']]])->assertCreated()->json();
        $this->assertSame(5242880, $result['attachments'][0]['file_size_bytes']);
        $this->postJson($this->url().'/uploads/discard', ['token' => $ticket['token'].'tampered'])->assertUnprocessable();
        $this->travel(11)->minutes();
        $this->postJson($this->url().'/uploads/discard', ['token' => $ticket['token']])->assertUnprocessable();
    }

    public function test_direct_upload_rejects_size_and_mime_forgery_and_cleanup_only_expires_staging(): void
    {
        $payload = $this->payload();
        $ticket = $this->postJson($this->url().'/uploads', ['announcement_id' => $payload['id'], 'file_name' => 'fake.pdf', 'size' => 12, 'mime_type' => 'application/pdf'])->assertOk()->json();
        $this->call('PUT', $ticket['url'], [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken], 'short')->assertUnprocessable();
        $this->call('PUT', $ticket['url'], [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken], '<html></html>')->assertUnprocessable();
        $this->call('PUT', $ticket['url'], [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken], 'hello world!')->assertOk();
        $this->postJson($this->url(), [...$payload, 'uploaded_attachment_tokens' => [$ticket['token']]])->assertUnprocessable();
        $this->assertDatabaseMissing('announcements', ['id' => $payload['id']]);
        $disk = Storage::disk('local');
        $expired = 'announcement-staging/'.now()->subHours(2)->timestamp.'/'.$this->adminUser->id.'/'.Str::uuid();
        $active = 'announcement-staging/'.now()->addMinutes(10)->timestamp.'/'.$this->adminUser->id.'/'.Str::uuid();
        $final = 'announcement-attachments/'.$payload['id'].'/'.Str::uuid().'.pdf';
        foreach ([$expired, $active, $final] as $path) {
            $disk->put($path, 'keep');
        }
        $this->artisan('announcements:cleanup-uploads', ['--dry-run' => true])->assertSuccessful();
        $disk->assertExists($expired);
        $this->artisan('announcements:cleanup-uploads')->assertSuccessful();
        $disk->assertMissing($expired);
        $disk->assertExists($active);
        $disk->assertExists($final);
    }

    public function test_remote_upload_and_download_use_signed_https_urls_without_storage_paths_in_posts(): void
    {
        config(['announcements.attachment_disk' => 'supabase', 'filesystems.disks.supabase.key' => 'qa-only', 'filesystems.disks.supabase.secret' => 'qa-only', 'filesystems.disks.supabase.region' => 'qa', 'filesystems.disks.supabase.bucket' => 'qa', 'filesystems.disks.supabase.endpoint' => 'https://storage.example.test']);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('temporaryUploadUrl')->once()->withArgs(fn ($path, $expires, $options) => str_starts_with($path, 'announcement-staging/') && $options['ContentLength'] === 12)->andReturn(['url' => 'https://storage.example.test/upload?signature=qa', 'headers' => ['Host' => ['storage.example.test'], 'Content-Length' => ['12'], 'Content-Type' => ['application/pdf']]]);
        Storage::shouldReceive('disk')->with('supabase')->andReturn($disk);
        $row = $this->create();
        $this->postJson($this->url().'/uploads', ['announcement_id' => $row['id'], 'file_name' => 'qa.pdf', 'size' => 12, 'mime_type' => 'application/pdf'])->assertOk()->assertJsonPath('transport', 's3')->assertJsonPath('headers', ['Content-Type' => 'application/pdf']);
        $id = (string) Str::uuid();
        $path = 'announcement-attachments/'.$row['id'].'/'.Str::uuid().'.pdf';
        DB::table('announcement_attachments')->insert(['id' => $id, 'announcement_id' => $row['id'], 'file_name' => 'qa.pdf', 'file_url' => $path, 'storage_disk' => 'supabase', 'mime_type' => 'application/pdf', 'file_size_bytes' => 12, 'created_at' => now()]);
        $disk->shouldReceive('exists')->with($path)->once()->andReturnTrue();
        $disk->shouldReceive('temporaryUrl')->once()->andReturn('https://storage.example.test/download?signature=qa');
        $this->getJson($this->url($row['id']).'/attachments/'.$id)->assertOk()->assertJsonPath('download_url', 'https://storage.example.test/download?signature=qa')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($this->url($row['id']))->assertOk()->assertJsonMissingPath('attachments.0.file_url');
    }

    public function test_failed_raw_upload_cleans_partial_staging_without_touching_existing_attachments(): void
    {
        $ticket = $this->postJson($this->url().'/uploads', ['announcement_id' => (string) Str::uuid(), 'file_name' => 'qa.pdf', 'size' => 8, 'mime_type' => 'application/pdf'])->assertOk()->json();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        $disk->shouldReceive('delete')->once()->withArgs(fn ($path) => str_starts_with($path, 'announcement-staging/'))->andReturnTrue();
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        $this->call('PUT', $ticket['url'], [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->adminToken], '%PDF-1.4')->assertStatus(500);
    }
}
