<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Services\TicketDiscussionService;
use Database\Seeders\TicketDiscussionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\ResidentAmenityBookingFixtures;
use Tests\TestCase;

class TicketDiscussionSeederTest extends TestCase
{
    use DatabaseTransactions;
    use ResidentAmenityBookingFixtures;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createBookingFixture();
        foreach (Ticket::whereIn('ticket_number', ['TCK-202610-00101', 'TCK-202610-00102', 'TCK-202610-00103', 'TCK-202610-00106'])->get() as $existing) {
            $existing->update(['ticket_number' => 'QA-'.Str::random(15)]);
        }
        $category = TicketCategory::create(['category_code' => 'SEED-'.Str::random(8), 'category_name' => 'QA trao đổi mẫu', 'default_priority' => 'MEDIUM']);
        $this->ticket = Ticket::create(['ticket_number' => 'TCK-202610-00101', 'category_id' => $category->id, 'creator_user_id' => $this->residentUser->id, 'apartment_id' => $this->apartmentId, 'title' => 'QA Ticket mẫu', 'description' => 'QA seeder trao đổi', 'location' => 'Bếp', 'priority' => 'MEDIUM', 'status' => 'NEW', 'version' => 1]);
    }

    public function test_demo_comments_are_idempotent_private_and_do_not_change_workflow(): void
    {
        $this->seed(TicketDiscussionSeeder::class);
        $comments = DB::table('ticket_comments')->where('ticket_id', $this->ticket->id)->get();
        $this->assertCount(20, $comments);
        $this->assertCount(1, $comments->where('is_internal_staff_note', true));
        $this->assertSame(20, DB::table('audit_logs')->whereIn('record_id', $comments->pluck('id'))->count());
        $notifications = DB::table('user_in_app_notifications')->where('deep_link_url', 'like', '%'.$this->ticket->id.'%')->count();
        $this->assertGreaterThan(0, $notifications);
        $this->seed(TicketDiscussionSeeder::class);
        $this->assertSame(20, DB::table('ticket_comments')->where('ticket_id', $this->ticket->id)->count());
        $this->assertSame($notifications, DB::table('user_in_app_notifications')->where('deep_link_url', 'like', '%'.$this->ticket->id.'%')->count());
        $this->assertSame('NEW', $this->ticket->fresh()->status);
        $this->assertSame(1, $this->ticket->fresh()->version);
        $this->assertSame(0, DB::table('ticket_history')->where('ticket_id', $this->ticket->id)->count());
        $page = app(TicketDiscussionService::class)->comments($this->residentUser, $this->ticket->id, null);
        $this->assertCount(15, $page['items']);
        $this->assertNotNull($page['next_cursor']);
        $this->assertFalse(collect($page['items'])->contains('is_internal_staff_note', true));
    }

    public function test_closed_ticket_is_not_reopened_or_seeded(): void
    {
        $this->ticket->update(['status' => 'CLOSED', 'version' => 5]);
        $this->seed(TicketDiscussionSeeder::class);
        $this->assertSame(0, DB::table('ticket_comments')->where('ticket_id', $this->ticket->id)->count());
        $this->assertSame(5, $this->ticket->fresh()->version);
    }

    public function test_production_is_blocked_even_when_explicitly_called(): void
    {
        $this->app->instance('env', 'production');
        try {
            (new TicketDiscussionSeeder)->run();
            $this->assertSame(0, DB::table('ticket_comments')->where('ticket_id', $this->ticket->id)->count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }
}
