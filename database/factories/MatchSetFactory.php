<?php

namespace Database\Factories;

use App\Models\MatchSet;
use App\Models\PadelMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchSet>
 */
class MatchSetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'match_id' => PadelMatch::factory(),
            'set_number' => fake()->numberBetween(1, 3),
            'side1_games' => fake()->numberBetween(0, 7),
            'side2_games' => fake()->numberBetween(0, 7),
        ];
    }
}
