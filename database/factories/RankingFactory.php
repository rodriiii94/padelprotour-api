<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Pair;
use App\Models\Ranking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ranking>
 */
class RankingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'pair_id' => Pair::factory(),
            'player_id' => null,
            'points' => fake()->numberBetween(0, 100),
            'position' => fake()->numberBetween(1, 32),
            'calculated_at' => now(),
        ];
    }
}
