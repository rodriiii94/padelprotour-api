<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Competition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'competition_id' => Competition::factory(),
            'name' => fake()->randomElement(['1ª', '2ª', '3ª', '4ª', '5ª']).' '.fake()->randomElement(['Masculina', 'Femenina', 'Mixta']),
            'match_format' => '3 sets, super tie-break',
            'slots' => fake()->numberBetween(8, 32),
            'registration_mode' => fake()->randomElement(['fixed_pair', 'individual_rotating']),
        ];
    }
}
