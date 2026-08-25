<?php

namespace Database\Factories;

use App\Models\PadelMatch;
use App\Models\Phase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PadelMatch>
 */
class PadelMatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phase_id' => Phase::factory(),
            'side1_player1_id' => User::factory(),
            'side1_player2_id' => User::factory(),
            'side2_player1_id' => User::factory(),
            'side2_player2_id' => User::factory(),
            'scheduled_at' => fake()->dateTimeBetween('now', '+1 month'),
            'court' => fake()->randomElement(['Pista 1', 'Pista 2', 'Pista 3']),
            'status' => 'scheduled',
            'winner_side' => null,
        ];
    }

    /**
     * Indicate that the match has been played and validated.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'winner_side' => fake()->randomElement([1, 2]),
        ]);
    }
}
