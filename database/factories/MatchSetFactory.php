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
        [$winnerGames, $loserGames] = fake()->randomElement([
            [6, 0], [6, 1], [6, 2], [6, 3], [6, 4], [7, 5], [7, 6],
        ]);
        $side1Wins = fake()->boolean();

        return [
            'match_id' => PadelMatch::factory(),
            'set_number' => fake()->numberBetween(1, 3),
            'side1_games' => $side1Wins ? $winnerGames : $loserGames,
            'side2_games' => $side1Wins ? $loserGames : $winnerGames,
        ];
    }
}
