<?php

namespace Tests\Feature;

use Database\Seeders\ResidentTicketSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentTicketSeederTest extends TestCase
{
    use DatabaseTransactions;

    public function test_ticket_seeder_creates_a_valid_apartment_and_can_run_twice(): void
    {
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        DB::table('apartments')->whereIn('apartment_number', ['B2-08', '1508'])->update(['apartment_number' => 'QA-'.Str::random(12)]);
        $block = DB::table('blocks')->where('block_code', 'BLOCK_B')->value('id');
        if (! $block) {
            $block = (string) Str::uuid();
            DB::table('blocks')->insert(['id' => $block, 'block_code' => 'BLOCK_B', 'block_name' => 'QA Block B']);
        }
        try {
            $this->seed(ResidentTicketSeeder::class);
            $apartment = DB::table('apartments')->where('block_id', $block)->where('apartment_number', 'B2-08')->first();
            $this->assertNotNull($apartment);
            $this->assertDatabaseHas('floors', ['id' => $apartment->floor_id, 'block_id' => $block]);
            $this->assertGreaterThan(0, (float) $apartment->gross_floor_area_sqm);
            $this->assertGreaterThan(0, (float) $apartment->net_usable_area_sqm);
            $tickets = DB::table('tickets')->count();
            $history = DB::table('ticket_history')->count();
            $this->seed(ResidentTicketSeeder::class);
            $this->assertSame($tickets, DB::table('tickets')->count());
            $this->assertSame($history, DB::table('ticket_history')->count());
            $this->assertSame(1, DB::table('apartments')->where('block_id', $block)->where('apartment_number', 'B2-08')->count());
        } finally {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
    }
}
