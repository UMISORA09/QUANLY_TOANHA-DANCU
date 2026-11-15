<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\AnnouncementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AnnouncementConcurrencyTest extends TestCase
{
    use ResidentAmenityBookingFixtures;

    private ?User $actor = null;

    private ?string $announcementId = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('TEST_HTTP_BASE_URL')) {
            $this->markTestSkipped('Requires isolated multi-worker QA HTTP server.');
        }
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->actor = User::factory()->create();
        $this->actor->assignRole(Role::where('role_code', 'SUPER_ADMIN')->firstOrFail());
        $this->announcementId = (string) Str::uuid();
        app(AnnouncementService::class)->save($this->actor, null, $this->payload());
    }

    private function payload(): array
    {
        return ['id' => $this->announcementId, 'title' => 'QA concurrent announcement', 'content_html' => '<p>QA publication</p>', 'category' => 'GENERAL', 'target_scope' => 'ALL_BLOCKS', 'is_pinned' => false, 'is_urgent' => false];
    }

    protected function tearDown(): void
    {
        if ($this->announcementId && $this->actor) {
            DB::table('audit_logs')->where('record_id', $this->announcementId)->delete();
            DB::table('announcements')->where('id', $this->announcementId)->delete();
            DB::table('user_sessions')->where('user_id', $this->actor->id)->delete();
            DB::table('user_roles')->where('user_id', $this->actor->id)->delete();
            DB::table('users')->where('id', $this->actor->id)->delete();
        }
        parent::tearDown();
    }

    private function race(array $operations): array
    {
        $token = $this->sessionToken($this->actor);
        $multi = curl_multi_init();
        $handles = [];
        DB::beginTransaction();
        DB::table('announcements')->where('id', $this->announcementId)->lockForUpdate()->first();
        try {
            foreach ($operations as [$suffix, $method, $payload]) {
                $handle = curl_init(rtrim(getenv('TEST_HTTP_BASE_URL'), '/').'/api/v1/admin/announcements/'.$this->announcementId.$suffix);
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
                    curl_multi_select($multi, .1);
                }
            } while ($running);
            $statuses = array_map(fn ($handle) => curl_getinfo($handle, CURLINFO_HTTP_CODE), $handles);
            sort($statuses);

            return $statuses;
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            } curl_multi_close($multi);
        }
    }

    public function test_parallel_publish_creates_one_version_and_one_audit(): void
    {
        $this->assertSame([200, 409], $this->race([['/publish', 'POST', ['version' => 1]], ['/publish', 'POST', ['version' => 1]]]));
        $this->assertDatabaseHas('announcements', ['id' => $this->announcementId, 'status' => 'PUBLISHED', 'version' => 2]);
        $this->assertSame(2, DB::table('audit_logs')->where('record_id', $this->announcementId)->count());
    }

    public function test_edit_racing_publish_cannot_overwrite_the_other_change(): void
    {
        $data = $this->payload();
        unset($data['id']);
        $this->assertSame([200, 409], $this->race([['', 'PATCH', [...$data, 'version' => 1, 'title' => 'QA edited']], ['/publish', 'POST', ['version' => 1]]]));
        $this->assertSame(2, (int) DB::table('announcements')->where('id', $this->announcementId)->value('version'));
        $this->assertSame(2, DB::table('audit_logs')->where('record_id', $this->announcementId)->count());
    }

    public function test_unpublish_racing_archive_keeps_only_one_successful_change(): void
    {
        app(AnnouncementService::class)->action($this->actor, $this->announcementId, 'publish', ['version' => 1]);
        $statuses = $this->race([['/unpublish', 'POST', ['version' => 2, 'reason' => 'QA unpublish reason']], ['', 'DELETE', ['version' => 2, 'reason' => 'QA archive reason']]]);
        $this->assertContains($statuses, [[200, 409], [200, 404]]);
        $this->assertSame(3, (int) DB::table('announcements')->where('id', $this->announcementId)->value('version'));
        $this->assertSame(3, DB::table('audit_logs')->where('record_id', $this->announcementId)->count());
    }
}
