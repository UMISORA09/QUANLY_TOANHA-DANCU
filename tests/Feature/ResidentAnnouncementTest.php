<?php

namespace Tests\Feature;

use App\Models\Resident;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class ResidentAnnouncementTest extends TestCase
{
    use DatabaseTransactions;
    use ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        Storage::fake('local');
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/resident/announcements'.$suffix;
    }

    private function article(array $values = []): string
    {
        $id = (string) Str::uuid();
        DB::table('announcements')->insert([...[
            'id' => $id, 'title' => 'QA cư dân '.Str::random(8), 'content_html' => '<h2>Lịch bảo trì</h2><p>Thông tin cư dân.</p>',
            'summary_excerpt' => 'Thông tin QA', 'category' => 'MAINTENANCE', 'target_scope' => 'BLOCK', 'target_block_id' => $this->blockId,
            'is_pinned' => false, 'is_urgent' => false, 'requires_acknowledgement' => false, 'publish_at' => now()->subMinute(),
            'expires_at' => now()->addDay(), 'author_user_id' => $this->adminUser->id, 'status' => 'PUBLISHED', 'version' => 1,
        ], ...$values]);

        return $id;
    }

    public function test_strict_sessions_active_account_and_valid_residency_are_required(): void
    {
        $this->withHeader('Authorization', '')->getJson($this->url())->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer smart_token_admin_demo')->getJson($this->url())->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->adminToken)->getJson($this->url())->assertForbidden();
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        DB::table('residents')->where('user_id', $this->residentUser->id)->update(['stay_end_date' => today()->subDay()]);
        $this->getJson($this->url())->assertForbidden();
        DB::table('residents')->where('user_id', $this->residentUser->id)->update(['stay_end_date' => null, 'stay_start_date' => today()->addDay()]);
        $this->getJson($this->url())->assertForbidden();
        DB::table('residents')->where('user_id', $this->residentUser->id)->update(['stay_start_date' => today()->subDay()]);
        DB::table('users')->where('id', $this->residentUser->id)->update(['status' => 'LOCKED']);
        $this->getJson($this->url())->assertForbidden();
        DB::table('users')->where('id', $this->residentUser->id)->update(['status' => 'ACTIVE']);
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['is_revoked' => 1]);
        $this->getJson($this->url())->assertUnauthorized();
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['is_revoked' => 0, 'expires_at' => now()->subMinute()]);
        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_scope_union_has_no_duplicates_and_receipts_are_private_per_account(): void
    {
        $floor = DB::table('apartments')->where('id', $this->apartmentId)->value('floor_id');
        $all = $this->article(['target_scope' => 'ALL_BLOCKS', 'target_block_id' => null]);
        $block = $this->article();
        $ownFloor = $this->article(['target_scope' => 'FLOOR', 'target_floor_id' => $floor]);
        $otherFloor = (string) Str::uuid();
        DB::table('floors')->insert(['id' => $otherFloor, 'block_id' => $this->blockId, 'floor_number' => 2, 'floor_code' => 'F2', 'floor_name' => 'QA tầng khác']);
        $outside = $this->article(['target_scope' => 'FLOOR', 'target_floor_id' => $otherFloor]);
        $foreignBlock = (string) Str::uuid();
        DB::table('blocks')->insert(['id' => $foreignBlock, 'block_code' => 'QA-'.Str::random(8), 'block_name' => 'QA khác']);
        $foreign = $this->article(['target_block_id' => $foreignBlock]);
        $sameFloorApartment = (string) Str::uuid();
        DB::table('apartments')->insert(['id' => $sameFloorApartment, 'block_id' => $this->blockId, 'floor_id' => $floor, 'apartment_number' => 'QA-102', 'gross_floor_area_sqm' => 80, 'net_usable_area_sqm' => 70]);
        Resident::factory()->create(['user_id' => $this->residentUser->id, 'apartment_id' => $sameFloorApartment]);
        $data = $this->getJson($this->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->assertEqualsCanonicalizing([$all, $block, $ownFloor], array_column($data['data'], 'id'));
        $this->assertSame(3, $data['unread_count']);
        $this->getJson($this->url('/'.$outside))->assertNotFound();
        $this->getJson($this->url('/'.$foreign))->assertNotFound();
        $this->postJson($this->url('/'.$foreign.'/read'), ['version' => 1])->assertNotFound();
        $this->postJson($this->url('/'.$all.'/read'), ['version' => 1])->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$this->secondToken)->getJson($this->url('/'.$all))->assertOk()->assertJsonPath('read_at', null);
        $this->withHeader('Authorization', 'Bearer '.$this->residentToken);
        $newApartment = (string) Str::uuid();
        DB::table('apartments')->insert(['id' => $newApartment, 'block_id' => $this->blockId, 'floor_id' => $otherFloor, 'apartment_number' => 'QA-202', 'gross_floor_area_sqm' => 80, 'net_usable_area_sqm' => 70]);
        Resident::factory()->create(['user_id' => $this->residentUser->id, 'apartment_id' => $newApartment]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('total', 4)->assertJsonPath('unread_count', 3);
    }

    public function test_only_currently_published_articles_are_visible_at_time_boundaries(): void
    {
        $this->travelTo(now()->startOfSecond());
        $live = $this->article(['publish_at' => now(), 'expires_at' => now()->addHour()]);
        $ids = [
            $this->article(['status' => 'DRAFT']), $this->article(['status' => 'UNPUBLISHED']),
            $this->article(['publish_at' => now()->addSecond()]), $this->article(['expires_at' => now()]),
            $this->article(['deleted_at' => now()]),
        ];
        $this->getJson($this->url())->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $live);
        foreach ($ids as $id) {
            $this->getJson($this->url('/'.$id))->assertNotFound();
            $this->postJson($this->url('/'.$id.'/acknowledge'), ['version' => 1])->assertNotFound();
        }
        $this->travel(2)->seconds();
        $this->getJson($this->url())->assertOk()->assertJsonPath('total', 2);
    }

    public function test_get_never_marks_read_and_retries_preserve_first_receipt_times(): void
    {
        $id = $this->article(['requires_acknowledgement' => true]);
        $this->getJson($this->url('/'.$id))->assertOk()->assertJsonPath('read_at', null);
        $this->assertDatabaseMissing('announcement_reads', ['announcement_id' => $id]);
        $read = $this->postJson($this->url('/'.$id.'/read'), ['version' => 1])->assertOk()->assertJsonPath('acknowledged_at', null)->json();
        $this->travel(1)->minutes();
        $this->postJson($this->url('/'.$id.'/read'), ['version' => 1])->assertOk()->assertJsonPath('read_at', $read['read_at']);
        $ack = $this->postJson($this->url('/'.$id.'/acknowledge'), ['version' => 1])->assertOk()->assertJsonPath('read_at', $read['read_at'])->json();
        $this->travel(1)->minutes();
        $this->postJson($this->url('/'.$id.'/acknowledge'), ['version' => 1])->assertOk()->assertJsonPath('acknowledged_at', $ack['acknowledged_at']);
        $this->assertSame(1, DB::table('announcement_reads')->where('announcement_id', $id)->count());
        $this->assertDatabaseHas('announcements', ['id' => $id, 'version' => 1]);
        DB::table('announcements')->where('id', $id)->update(['version' => 2]);
        $this->getJson($this->url('/'.$id))->assertOk()->assertJsonPath('read_at', $read['read_at'])->assertJsonPath('acknowledged_at', $ack['acknowledged_at']);
        $this->getJson($this->url('?filter=unread'))->assertOk()->assertJsonPath('total', 0)->assertJsonPath('unread_count', 0);
        $this->getJson($this->url('?filter=acknowledgement'))->assertOk()->assertJsonPath('total', 0);
    }

    public function test_changed_version_invalid_payload_and_withdrawal_cannot_be_acknowledged(): void
    {
        $id = $this->article(['requires_acknowledgement' => true]);
        $this->postJson($this->url('/'.$id.'/read'), [])->assertUnprocessable();
        $this->postJson($this->url('/'.$id.'/read'), ['version' => 1, 'user_id' => $this->secondUser->id])->assertUnprocessable();
        $this->postJson($this->url('/'.$id.'/acknowledge'), ['version' => 1, 'acknowledged_at' => now()])->assertUnprocessable();
        DB::table('announcements')->where('id', $id)->update(['version' => 2]);
        $this->postJson($this->url('/'.$id.'/read'), ['version' => 1])->assertConflict();
        $this->postJson($this->url('/'.$id.'/acknowledge'), ['version' => 1])->assertConflict();
        $this->assertDatabaseMissing('announcement_reads', ['announcement_id' => $id]);
        $this->postJson($this->url('/'.$id.'/acknowledge'), ['version' => 2])->assertOk();
        DB::table('announcements')->where('id', $id)->update(['status' => 'UNPUBLISHED']);
        $this->postJson($this->url('/'.$id.'/acknowledge'), ['version' => 2])->assertNotFound();
        $ordinary = $this->article();
        $this->postJson($this->url('/'.$ordinary.'/acknowledge'), ['version' => 1])->assertUnprocessable();
    }

    public function test_filters_pagination_and_pinned_order_are_stable(): void
    {
        $ids = [];
        for ($i = 0; $i < 31; $i++) {
            $ids[] = $this->article(['title' => 'QA nhóm '.$i, 'publish_at' => now()->subHour(), 'requires_acknowledgement' => $i % 2 === 0]);
        }
        $pinned = $this->article(['is_pinned' => true, 'publish_at' => now()->subDay()]);
        $one = $this->getJson($this->url())->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('data.0.id', $pinned)->json();
        $two = $this->getJson($this->url('?page=2'))->assertOk()->assertJsonCount(15, 'data')->json();
        $this->assertEmpty(array_intersect(array_column($one['data'], 'id'), array_column($two['data'], 'id')));
        $this->getJson($this->url('?page=3'))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->url('?filter=acknowledgement'))->assertOk()->assertJsonPath('total', 16);
        $this->getJson($this->url('?search=QA%20nhóm%2030'))->assertOk()->assertJsonPath('total', 1);
        $this->getJson($this->url('?category=GENERAL'))->assertOk()->assertJsonPath('total', 0)->assertJsonPath('unread_count', 32);
        $this->getJson($this->url('?page=-1'))->assertUnprocessable();
        $this->getJson($this->url('?filter=invalid'))->assertUnprocessable();
    }

    public function test_html_and_attachment_authorization_do_not_expose_private_paths(): void
    {
        $id = $this->article(['content_html' => '<p onclick="x()">Xin chào</p><script>alert(1)</script><a href="javascript:alert(1)">Xấu</a>']);
        $fileId = (string) Str::uuid();
        $path = 'announcement-attachments/'.$id.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 QA');
        DB::table('announcement_attachments')->insert(['id' => $fileId, 'announcement_id' => $id, 'file_name' => 'QA.pdf', 'file_url' => $path, 'storage_disk' => 'local', 'mime_type' => 'application/pdf', 'file_size_bytes' => 11]);
        $row = $this->getJson($this->url('/'.$id))->assertOk()->json();
        $this->assertStringNotContainsString('script', $row['content_html']);
        $this->assertStringNotContainsString('onclick', $row['content_html']);
        $this->assertStringNotContainsString('javascript:', $row['content_html']);
        $this->assertArrayNotHasKey('file_url', $row['attachments'][0]);
        $this->assertArrayNotHasKey('storage_disk', $row['attachments'][0]);
        $this->assertArrayNotHasKey('author_user_id', $row);
        $this->getJson($this->url('/'.$id.'/attachments/'.$fileId))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        Storage::disk('local')->delete($path);
        $this->getJson($this->url('/'.$id.'/attachments/'.$fileId))->assertNotFound();
        $other = $this->article();
        $this->getJson($this->url('/'.$other.'/attachments/'.$fileId))->assertNotFound();
        DB::table('residents')->where('user_id', $this->residentUser->id)->update(['is_active' => 0]);
        $this->getJson($this->url('/'.$id.'/attachments/'.$fileId))->assertForbidden();
    }

    public function test_receipt_insert_failure_rolls_back_and_unique_index_already_exists(): void
    {
        $this->assertTrue(collect(Schema::getIndexes('announcement_reads'))->contains(fn (array $index) => $index['unique'] && $index['columns'] === ['announcement_id', 'user_id']));
        $id = $this->article();
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into `announcement_reads`')) {
                throw new RuntimeException('QA receipt failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->url('/'.$id.'/read'), ['version' => 1]);
            $this->fail('Expected receipt failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('QA receipt failure', $error->getMessage());
        }
        $this->assertDatabaseMissing('announcement_reads', ['announcement_id' => $id]);
    }
}
