<?php

namespace Tests\Feature;

use App\Models\Apartment;
use App\Models\Block;
use App\Models\Floor;
use App\Models\Resident;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketCategory;
use App\Models\TicketRating;
use App\Models\User;
use App\Services\ResidentTicketService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TicketEagerLoadingTest extends TestCase
{
    use DatabaseTransactions;

    protected User $residentUser;

    protected Apartment $apartment;

    protected TicketCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Model::preventLazyLoading(true);

        $this->residentUser = User::factory()->create([
            'status' => 'ACTIVE',
        ]);

        $block = Block::firstOrCreate(
            ['block_code' => 'B_N1_TEST'],
            [
                'id' => (string) Str::uuid(),
                'block_name' => 'Block N1 Test',
                'total_floors' => 5,
            ]
        );

        $floor = Floor::firstOrCreate(
            ['block_id' => $block->id, 'floor_number' => 2],
            [
                'id' => (string) Str::uuid(),
                'floor_code' => 'FL_N1_2',
                'floor_name' => 'Floor 2',
            ]
        );

        $this->apartment = Apartment::firstOrCreate(
            ['apartment_number' => 'N1-201'],
            [
                'id' => (string) Str::uuid(),
                'block_id' => $block->id,
                'floor_id' => $floor->id,
                'gross_floor_area_sqm' => 80.0,
                'net_usable_area_sqm' => 75.0,
                'status' => 'OCCUPIED',
            ]
        );

        Resident::firstOrCreate(
            ['user_id' => $this->residentUser->id, 'apartment_id' => $this->apartment->id],
            [
                'id' => (string) Str::uuid(),
                'relationship_with_owner' => 'CHỦ HỘ',
                'stay_start_date' => now()->subMonth(),
                'is_active' => true,
            ]
        );

        $this->category = TicketCategory::firstOrCreate(
            ['category_code' => 'PLUMB_TEST'],
            [
                'id' => (string) Str::uuid(),
                'category_name' => 'Sửa chữa điện nước',
                'default_priority' => 'MEDIUM',
            ]
        );

        // Tạo 5 tickets kèm attachments và ratings
        for ($i = 1; $i <= 5; $i++) {
            $ticket = Ticket::create([
                'id' => (string) Str::uuid(),
                'ticket_number' => 'TCK-N1-'.Str::random(6),
                'category_id' => $this->category->id,
                'apartment_id' => $this->apartment->id,
                'creator_user_id' => $this->residentUser->id,
                'title' => "Ticket {$i} N1 Test",
                'description' => "Test description for ticket {$i}",
                'priority' => 'MEDIUM',
                'status' => 'NEW',
            ]);

            TicketAttachment::create([
                'id' => (string) Str::uuid(),
                'ticket_id' => $ticket->id,
                'file_name' => "photo_{$i}.jpg",
                'file_url' => "https://example.com/tickets/photo_{$i}.jpg",
                'storage_path' => "tickets/photo_{$i}.jpg",
                'storage_disk' => 'local',
                'file_type' => 'IMAGE',
                'file_size_bytes' => 1024,
                'mime_type' => 'image/jpeg',
                'uploaded_by_user_id' => $this->residentUser->id,
            ]);

            TicketRating::create([
                'id' => (string) Str::uuid(),
                'ticket_id' => $ticket->id,
                'resident_user_id' => $this->residentUser->id,
                'rating_stars' => 5,
                'review_comment' => "Dịch vụ tốt {$i}",
                'created_at' => now(),
            ]);
        }
    }

    public function test_resident_ticket_listing_eager_loads_relations_without_n_plus_one(): void
    {
        $service = app(ResidentTicketService::class);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $result = $service->list($this->residentUser, []);
        $this->assertNotEmpty($result['tickets']);

        // Duyệt qua tất cả quan hệ để đảm bảo Model::preventLazyLoading(true) không ném exception
        foreach ($result['tickets'] as $ticket) {
            $this->assertNotNull($ticket->category);
            $this->assertNotNull($ticket->apartment);
            $this->assertNotNull($ticket->apartment->block);
            $this->assertCount(1, $ticket->attachments);
            $this->assertNotNull($ticket->rating);
        }

        // Đảm bảo không phát sinh N+1 truy vấn lặp lại theo số lượng tickets (5 tickets không sinh 5 query attachments hay rating)
        $this->assertLessThanOrEqual(15, count($queries), 'Query count exceeds expected limit with eager loading.');
    }
}
