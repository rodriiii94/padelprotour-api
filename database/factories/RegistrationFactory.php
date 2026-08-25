<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Pair;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Registration>
 */
class RegistrationFactory extends Factory
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
            'status' => fake()->randomElement(['pending', 'confirmed', 'waitlisted', 'rejected']),
        ];
    }

    /**
     * Indicate that the registration is for an individual player rather than a pair.
     */
    public function forPlayer(): static
    {
        return $this->state(fn (array $attributes) => [
            'pair_id' => null,
            'player_id' => User::factory(),
        ]);
    }
}
