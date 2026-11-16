<?php

namespace Database\Factories;

use App\Models\Resident;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Resident>
 */
class ResidentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'resident_type' => 'OWNER',
            'is_head_of_household' => true,
            'stay_start_date' => today()->subYear(),
            'stay_end_date' => null,
            'relationship_to_head' => 'SELF',
            'is_active' => true,
        ];
    }
}
