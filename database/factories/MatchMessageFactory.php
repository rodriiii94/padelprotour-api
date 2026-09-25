<?php

namespace Database\Factories;

use App\Models\MatchMessage;
use App\Models\PadelMatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MatchMessage>
 */
class MatchMessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'match_id' => PadelMatch::factory(),
            'author_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
