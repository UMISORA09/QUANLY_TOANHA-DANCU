<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Bộ dữ liệu nền hệ thống
        $this->call([
            ManagementDashboardSeeder::class,
            RbacSeeder::class,
            ResidentPortalSeeder::class,
            ReceptionPortalSeeder::class,
            AmenitySeeder::class,
        ]);

        // Bộ dữ liệu mẫu dùng chung cho toàn bộ nhóm (Shared Demo Data)
        if (
            ! app()->environment('production') &&
            filter_var(env('SEED_DEMO_DATA', true), FILTER_VALIDATE_BOOLEAN)
        ) {
            $this->call(SharedDemoDataSeeder::class);
        }
    }
}
