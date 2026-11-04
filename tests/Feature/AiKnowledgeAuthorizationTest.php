<?php

namespace Tests\Feature;

use App\Models\Resident;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class AiKnowledgeAuthorizationTest extends TestCase
{
    use DatabaseTransactions;
    use ResidentAmenityBookingFixtures;

    private string $query;

    private string $foreignBlockId;

    private array $documentIds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        $this->query = 'knowledge-'.Str::random(12);
        $this->foreignBlockId = (string) Str::uuid();
        DB::table('blocks')->insert(['id' => $this->foreignBlockId, 'block_code' => 'AI-'.Str::random(8), 'block_name' => 'Other QA block']);
        $this->documentIds = [
            'common' => $this->document(null),
            'own' => $this->document($this->blockId),
            'foreign' => $this->document($this->foreignBlockId),
        ];
    }

    private function document(?string $blockId): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_knowledge_documents')->insert(['id' => $id, 'doc_title' => $this->query, 'category' => 'RULES', 'block_id' => $blockId]);
        DB::table('ai_knowledge_chunks')->insert(['id' => (string) Str::uuid(), 'document_id' => $id, 'chunk_index' => 0, 'chunk_content' => $this->query, 'metadata' => '{}']);

        return $id;
    }

    private function url(array $params = []): string
    {
        return '/api/v1/search/ai-knowledge?'.http_build_query(['q' => $this->query, ...$params]);
    }

    public function test_guest_and_client_identity_headers_cannot_read_knowledge(): void
    {
        $this->withHeader('Authorization', '')->getJson($this->url())->assertUnauthorized();
        $this->withHeaders(['X-User-Id' => $this->adminUser->id, 'X-User-Email' => $this->adminUser->email])
            ->getJson($this->url(['user_id' => $this->adminUser->id]))->assertUnauthorized();
        $this->withToken('smart_token_admin_demo')->getJson($this->url())->assertUnauthorized();
    }

    public function test_resident_scope_is_applied_before_results_are_limited(): void
    {
        $response = $this->getJson($this->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertEqualsCanonicalizing([$this->documentIds['common'], $this->documentIds['own']], array_column($response->json('data'), 'document_id'));
        $this->getJson($this->url(['building_id' => $this->foreignBlockId]))->assertForbidden();
        $response = $this->getJson($this->url(['building_id' => $this->blockId]))->assertOk();
        $this->assertEqualsCanonicalizing([$this->documentIds['common'], $this->documentIds['own']], array_column($response->json('data'), 'document_id'));
    }

    public function test_multiple_current_residences_are_unioned_but_expired_residences_are_excluded(): void
    {
        $floorId = (string) Str::uuid();
        $apartmentId = (string) Str::uuid();
        DB::table('floors')->insert(['id' => $floorId, 'block_id' => $this->foreignBlockId, 'floor_number' => 1, 'floor_code' => 'AI-F1', 'floor_name' => 'QA floor']);
        DB::table('apartments')->insert(['id' => $apartmentId, 'block_id' => $this->foreignBlockId, 'floor_id' => $floorId, 'apartment_number' => 'AI-'.Str::random(8), 'gross_floor_area_sqm' => 80, 'net_usable_area_sqm' => 70]);
        $residence = Resident::factory()->create(['user_id' => $this->residentUser->id, 'apartment_id' => $apartmentId]);
        $this->assertEqualsCanonicalizing(array_values($this->documentIds), array_column($this->getJson($this->url())->assertOk()->json('data'), 'document_id'));
        $residence->update(['stay_end_date' => today()->subDay()]);
        $this->getJson($this->url(['building_id' => $this->foreignBlockId]))->assertForbidden();
        DB::table('residents')->where('user_id', $this->residentUser->id)->update(['stay_end_date' => today()->subDay()]);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_sessions_and_active_account_are_rechecked(): void
    {
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['is_revoked' => 1]);
        $this->getJson($this->url())->assertUnauthorized();
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['is_revoked' => 0, 'expires_at' => now()->subMinute()]);
        $this->getJson($this->url())->assertUnauthorized();
        DB::table('user_sessions')->where('user_id', $this->residentUser->id)->update(['expires_at' => now()->addHour()]);
        $this->residentUser->update(['status' => 'LOCKED']);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_management_uses_staff_profile_scope_and_admin_can_search_all_blocks(): void
    {
        $manager = User::factory()->create();
        Role::firstOrCreate(['role_code' => 'BUILDING_MANAGER'], ['role_name' => 'QA manager']);
        $manager->assignRole('BUILDING_MANAGER');
        $this->withToken($this->sessionToken($manager))->getJson($this->url())->assertForbidden();
        $department = (string) Str::uuid();
        DB::table('departments')->insert(['id' => $department, 'department_code' => 'AI-'.Str::random(8), 'department_name' => 'QA']);
        DB::table('staff_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $manager->id, 'department_id' => $department, 'employee_code' => 'AI-'.Str::random(8), 'job_title' => 'Manager', 'hire_date' => today(), 'assigned_block_id' => $this->blockId]);
        $this->assertEqualsCanonicalizing([$this->documentIds['common'], $this->documentIds['own']], array_column($this->getJson($this->url())->assertOk()->json('data'), 'document_id'));
        $this->getJson($this->url(['building_id' => $this->foreignBlockId]))->assertForbidden();
        DB::table('staff_profiles')->where('user_id', $manager->id)->update(['assigned_block_id' => null]);
        $this->getJson($this->url())->assertForbidden();
        $this->withToken($this->adminToken)->getJson($this->url())->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_invalid_search_parameters_are_rejected(): void
    {
        foreach ([['building_id' => 'invalid'], ['q' => ['invalid']], ['limit' => 21], ['categories' => ['invalid']]] as $params) {
            $this->getJson($this->url($params))->assertUnprocessable();
        }
    }
}
