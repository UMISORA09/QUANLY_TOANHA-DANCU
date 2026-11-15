<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class ResidentAnnouncementConcurrencyTest extends TestCase
{
    use ResidentAmenityBookingFixtures;

    private ?string $announcementId = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (! getenv('TEST_HTTP_BASE_URL')) {
            $this->markTestSkipped('Requires isolated multi-worker *_test HTTP server.');
        }
        $this->createBookingFixture();
        $this->announcementId = (string) Str::uuid();
        DB::table('announcements')->insert(['id' => $this->announcementId, 'title' => 'QA concurrent receipt', 'content_html' => '<p>QA</p>', 'target_scope' => 'BLOCK', 'target_block_id' => $this->blockId, 'author_user_id' => $this->adminUser->id, 'requires_acknowledgement' => true, 'status' => 'PUBLISHED', 'version' => 1, 'publish_at' => now()->subMinute()]);
    }

    protected function tearDown(): void
    {
        if (isset($this->amenityId)) {
            DB::table('announcement_reads')->where('announcement_id', $this->announcementId)->delete();
            DB::table('audit_logs')->where('record_id', $this->announcementId)->delete();
            DB::table('announcements')->where('id', $this->announcementId)->delete();
            DB::table('amenity_time_slots')->where('amenity_id', $this->amenityId)->delete();
            DB::table('amenities')->where('id', $this->amenityId)->delete();
            DB::table('amenity_categories')->where('id', $this->categoryId)->delete();
            $users = [$this->residentUser->id, $this->secondUser->id, $this->adminUser->id];
            foreach (['residents', 'user_sessions', 'user_roles'] as $table) {
                DB::table($table)->whereIn('user_id', $users)->delete();
            }
            DB::table('apartments')->where('block_id', $this->blockId)->delete();
            DB::table('floors')->where('block_id', $this->blockId)->delete();
            DB::table('blocks')->where('id', $this->blockId)->delete();
            DB::table('users')->whereIn('id', $users)->delete();
        }
        parent::tearDown();
    }

    /** @param array<array{string, string, array<string, mixed>}> $requests
     * @return array<int, int>
     */
    private function race(array $requests): array
    {
        $multi = curl_multi_init();
        $handles = [];
        DB::beginTransaction();
        DB::table('announcements')->where('id', $this->announcementId)->lockForUpdate()->first();
        try {
            foreach ($requests as [$path, $token, $payload]) {
                $handle = curl_init(rtrim(getenv('TEST_HTTP_BASE_URL'), '/').$path);
                curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$token, 'Accept: application/json', 'Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45]);
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
            }
            curl_multi_close($multi);
        }
    }

    public function test_parallel_reads_and_acknowledgements_create_only_one_receipt(): void
    {
        $base = '/api/v1/resident/announcements/'.$this->announcementId;
        $this->assertSame([200, 200], $this->race([[$base.'/read', $this->residentToken, ['version' => 1]], [$base.'/acknowledge', $this->residentToken, ['version' => 1]]]));
        $this->assertSame(1, DB::table('announcement_reads')->where('announcement_id', $this->announcementId)->count());
        $this->assertNotNull(DB::table('announcement_reads')->where('announcement_id', $this->announcementId)->value('acknowledged_at'));
        $this->assertDatabaseHas('announcements', ['id' => $this->announcementId, 'version' => 1]);
    }

    public function test_unpublish_racing_acknowledgement_leaves_no_invalid_new_receipt(): void
    {
        $base = '/api/v1/resident/announcements/'.$this->announcementId;
        $statuses = $this->race([[$base.'/acknowledge', $this->residentToken, ['version' => 1]], ['/api/v1/admin/announcements/'.$this->announcementId.'/unpublish', $this->adminToken, ['version' => 1, 'reason' => 'QA concurrent withdrawal']]]);
        $this->assertContains($statuses, [[200, 200], [200, 404]]);
        $this->assertDatabaseHas('announcements', ['id' => $this->announcementId, 'version' => 2, 'status' => 'UNPUBLISHED']);
        $count = DB::table('announcement_reads')->where('announcement_id', $this->announcementId)->count();
        $this->assertLessThanOrEqual(1, $count);
        if (in_array(404, $statuses, true)) {
            $this->assertSame(0, $count);
        }
    }
}
