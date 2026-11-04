<?php

namespace Tests\Feature;

use App\Services\Search\AmenitySearchSyncState;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class ResidentAmenitySearchTest extends TestCase
{
    use DatabaseTransactions;
    use ResidentAmenityBookingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        config(['search.default' => 'smart', 'search.drivers.algolia.sync_enabled' => false]);
        Http::preventStrayRequests();
    }

    private function url(string $suffix = 'search', array $parameters = []): string
    {
        return '/api/v1/resident/amenities/'.$suffix.'?'.http_build_query(['apartment_id' => $this->apartmentId, ...$parameters]);
    }

    private function copyAmenity(array $changes = []): string
    {
        $id = (string) Str::uuid();
        $data = (array) DB::table('amenities')->where('id', $this->amenityId)->first();
        DB::table('amenities')->insert([...$data, 'id' => $id, 'amenity_code' => 'QA-'.Str::random(12), ...$changes]);

        return $id;
    }

    public function test_catalog_metadata_is_small_and_the_original_contract_is_preserved(): void
    {
        $this->getJson('/api/v1/resident/amenities')->assertOk()->assertJsonFragment(['id' => $this->amenityId]);
        $this->getJson('/api/v1/resident/amenities?metadata_only=1')->assertOk()->assertJsonCount(0, 'amenities')->assertJsonFragment(['id' => $this->categoryId]);
    }

    public function test_resident_search_and_private_detail_require_current_session_and_residence(): void
    {
        $this->withHeader('Authorization', '')->getJson($this->url())->assertUnauthorized();
        $this->withToken('smart_token_'.$this->residentUser->id.'_fake')->getJson($this->url())->assertUnauthorized();
        $this->withToken($this->residentToken);
        DB::table('residents')->where('user_id', $this->residentUser->id)->update(['stay_end_date' => today()->subDay()]);
        $this->getJson($this->url())->assertForbidden();
        $this->getJson($this->url($this->amenityId))->assertForbidden();
    }

    public function test_scope_and_active_status_are_applied_before_totals_and_pagination(): void
    {
        $foreignBlock = (string) Str::uuid();
        DB::table('blocks')->insert(['id' => $foreignBlock, 'block_code' => 'QA-'.Str::random(12), 'block_name' => 'Other block']);
        $foreign = $this->copyAmenity(['block_id' => $foreignBlock]);
        $inactive = $this->copyAmenity(['is_active' => false]);
        $shared = $this->copyAmenity(['block_id' => null]);
        $response = $this->getJson($this->url('search', ['category_id' => $this->categoryId]))->assertOk()->assertJsonPath('total', 2);
        $ids = array_column($response->json('items'), 'id');
        $this->assertContains($shared, $ids);
        $this->assertNotContains($foreign, $ids);
        $this->assertNotContains($inactive, $ids);
        $this->getJson($this->url($foreign))->assertNotFound();
        $this->getJson($this->url($inactive))->assertStatus(409);
        $this->getJson($this->url('search', ['apartment_id' => (string) Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('apartment_id');
    }

    public function test_pagination_has_fifteen_rows_and_no_duplicates_with_equal_timestamps(): void
    {
        for ($index = 0; $index < 20; $index++) {
            $this->copyAmenity(['amenity_name' => 'Same QA name', 'created_at' => '2026-01-01 00:00:00']);
        }
        $first = $this->getJson($this->url('search', ['category_id' => $this->categoryId]))->assertOk()->assertJsonCount(15, 'items')->assertJsonPath('total', 21)->assertJsonPath('total_pages', 2);
        $second = $this->getJson($this->url('search', ['category_id' => $this->categoryId, 'page' => 2]))->assertOk()->assertJsonCount(6, 'items');
        $this->assertSame([], array_values(array_intersect(array_column($first->json('items'), 'id'), array_column($second->json('items'), 'id'))));
    }

    public function test_unaccented_typo_and_exact_codes_work_without_manager_permissions(): void
    {
        DB::table('amenities')->where('id', $this->amenityId)->update(['amenity_name' => 'Phòng Gym', 'amenity_code' => 'GYM-QA-999']);
        $this->getJson($this->url('search', ['q' => 'phong gim']))->assertOk()->assertJsonPath('items.0.id', $this->amenityId);
        $this->getJson($this->url('search', ['q' => 'GYM-QA-999']))->assertOk()->assertJsonPath('items.0.id', $this->amenityId);
        $this->getJson($this->url('suggestions', ['q' => 'phong gim']))->assertOk()->assertJsonPath('suggestions.0.id', $this->amenityId);
        $response = $this->getJson($this->url($this->amenityId))->assertOk()->assertJsonPath('amenity_name', 'Phòng Gym');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_algolia_receives_scope_before_pagination_and_rechecks_mysql(): void
    {
        config(['search.default' => 'algolia', 'search.drivers.algolia' => ['app_id' => 'TESTAPP', 'key' => 'qa-key', 'amenities_index' => 'amenities_test', 'sync_enabled' => true]]);
        foreach (DB::table('amenities')->pluck('id') as $id) {
            DB::table('amenity_search_states')->updateOrInsert(['amenity_id' => $id], ['desired_version' => 1, 'published_version' => 1, 'status' => 'synced', 'created_at' => now(), 'updated_at' => now(), 'index_fingerprint' => AmenitySearchSyncState::fingerprint()]);
        }
        Http::fake(['*/query' => Http::response(['hits' => [['objectID' => $this->amenityId, 'search_version' => 1, 'updated_at' => DB::table('amenities')->where('id', $this->amenityId)->value('updated_at')]], 'nbHits' => 1])]);
        $this->getJson($this->url('search', ['q' => 'BBQ']))->assertOk()->assertJsonPath('engine', 'algolia');
        Http::assertSent(fn ($request) => $request['hitsPerPage'] === 15 && in_array(['block_id:'.$this->blockId, 'block_id:__shared__'], $request['facetFilters'], true));
    }

    public function test_another_account_cannot_reuse_an_authorized_search_or_private_detail(): void
    {
        $this->getJson($this->url())->assertOk();
        DB::table('residents')->where('user_id', $this->secondUser->id)->update(['is_active' => false]);
        $this->withToken($this->secondToken)->getJson($this->url())->assertForbidden();
        $this->getJson($this->url($this->amenityId))->assertForbidden();
    }

    public function test_invalid_filters_are_rejected_before_searching(): void
    {
        $this->getJson($this->url('search', ['page' => 0, 'q' => str_repeat('x', 201), 'category_id' => 'bad']))->assertUnprocessable()->assertJsonValidationErrors(['page', 'q', 'category_id']);
        Http::assertNothingSent();
    }
}
