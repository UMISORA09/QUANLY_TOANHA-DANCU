<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBearer;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ResidentTicketService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;

class ResidentTicketRouteRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null, 'cache.default' => 'array', 'session.driver' => 'array', 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        DB::purge('sqlite');
    }

    public function test_both_ticket_creation_routes_still_require_authentication(): void
    {
        DB::listen(fn () => $this->fail('Guest request must not reach the database.'));
        foreach (['/api/v1/resident/tickets', '/api/resident/tickets'] as $path) {
            $this->postJson($path, [])->assertUnauthorized();
        }
    }

    public function test_both_aliases_keep_form_validation_and_forward_to_the_canonical_service(): void
    {
        $this->withoutMiddleware(AuthenticateBearer::class);
        $user = new User;
        $user->id = '11111111-1111-4111-8111-111111111111';
        $this->actingAs($user);
        Schema::create('ticket_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        $category = '22222222-2222-4222-8222-222222222222';
        DB::table('ticket_categories')->insert(['id' => $category]);
        $service = Mockery::mock(ResidentTicketService::class);
        $ticket = new Ticket(['title' => 'Sự cố đèn', 'status' => 'NEW']);
        $ticket->id = '33333333-3333-4333-8333-333333333333';
        $service->shouldReceive('create')->twice()->withArgs(fn (User $actor, array $data, ?array $files, ?string $key): bool => $actor->id === $user->id && $data['location'] === 'Phòng khách' && ! array_key_exists('creator_user_id', $data) && $key === 'qa-submit')->andReturn($ticket);
        $this->app->instance(ResidentTicketService::class, $service);
        foreach (['/api/v1/resident/tickets', '/api/resident/tickets'] as $path) {
            $this->postJson($path, [])->assertUnprocessable()->assertJsonValidationErrors(['title', 'description', 'category_id', 'location']);
            $this->postJson($path, ['title' => 'Sự cố đèn', 'description' => 'Đèn phòng khách bị hỏng', 'category_id' => $category, 'location' => 'Phòng khách', 'creator_user_id' => 'forged'], ['X-Idempotency-Key' => 'qa-submit'])->assertCreated()->assertJsonPath('data.id', $ticket->id);
        }
    }
}
