<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Search\SearchManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SearchPerformanceTest extends TestCase
{
    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->admin = User::factory()->create(['status' => 'ACTIVE']);
        $this->admin->assignRole('SUPER_ADMIN');
        $this->token = $this->issueTestBearerToken($this->admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            DB::table('user_sessions')->where('user_id', $this->admin->id)->delete();
            DB::table('user_roles')->where('user_id', $this->admin->id)->delete();
            DB::table('users')->where('id', $this->admin->id)->delete();
        }
        parent::tearDown();
    }

    public function test_search_manager_returns_cached_results_with_hit_miss_headers(): void
    {
        $searchManager = app(SearchManager::class);

        // First call: MISS
        $result1 = $searchManager->search('amenities', 'gym', [], ['request_kind' => 'test']);
        $this->assertSame('MISS', request()->attributes->get('x_cache_status'));

        // Second call: HIT
        $result2 = $searchManager->search('amenities', 'gym', [], ['request_kind' => 'test']);
        $this->assertSame('HIT', request()->attributes->get('x_cache_status'));
        $this->assertEquals($result1->total, $result2->total);

        // Invalidate
        $searchManager->flushSearchEntityCache('amenities');

        // Third call after flush: MISS
        $result3 = $searchManager->search('amenities', 'gym', [], ['request_kind' => 'test']);
        $this->assertSame('MISS', request()->attributes->get('x_cache_status'));
    }

    public function test_api_search_returns_performance_headers(): void
    {
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json',
        ])->get('/api/amenities/search?q=pool');

        $response1->assertOk();
        $this->assertTrue($response1->headers->has('X-Response-Time'));
        $this->assertTrue($response1->headers->has('X-Cache'));
        $this->assertSame('MISS', $response1->headers->get('X-Cache'));

        // Second request
        $response2 = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json',
        ])->get('/api/amenities/search?q=pool');

        $this->assertTrue($response2->headers->has('X-Response-Time'));
        $this->assertSame('HIT', $response2->headers->get('X-Cache'));
    }
}
