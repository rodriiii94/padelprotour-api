<?php

namespace Database\Factories;

use App\Models\MatchdayPairing;
use App\Models\Phase;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchdayPairing>
 */
class MatchdayPairingFactory extends Factory
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
            'player1_id' => User::factory(),
            'player2_id' => User::factory(),
        ];
    }
}
