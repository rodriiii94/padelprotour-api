<?php

namespace Database\Factories;

use App\Models\Pair;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pair>
 */
class PairFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'player1_id' => User::factory(),
            'player2_id' => User::factory(),
        ];
    }
}
