<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Phase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Phase>
 */
class PhaseFactory extends Factory
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
            'type' => fake()->randomElement(['group', 'elimination_round', 'matchday']),
            'name' => fake()->randomElement(['Cuartos de final', 'Semifinal', 'Final', 'Jornada 1', 'Jornada 2']),
            'order' => fake()->numberBetween(1, 8),
        ];
    }
}
