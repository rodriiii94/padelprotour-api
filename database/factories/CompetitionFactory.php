<?php

namespace Database\Factories;

use App\Models\Competition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Competition>
 */
class CompetitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('now', '+2 months');

        return [
            'type' => fake()->randomElement(['tournament', 'league']),
            'name' => fake()->words(3, true),
            'venue' => fake()->company(),
            'start_date' => $startDate,
            'end_date' => fake()->dateTimeBetween($startDate, '+4 months'),
            'organizer_id' => User::factory(),
            'registration_closes_at' => fake()->dateTimeBetween('now', $startDate),
        ];
    }
}
