<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\PadelMatch;
use App\Models\User;

/**
 * Resumen de un partido pensado para un asistente: nombres en vez de ids, lado del usuario,
 * sets y reserva de pista. Nunca incluye emails.
 */
trait PresentsMatches
{
    /** @var list<string> */
    protected array $matchRelations = [
        'phase.category.competition:id,name,cancelled_at',
        'matchSets',
        'side1Player1:id,name',
        'side1Player2:id,name',
        'side2Player1:id,name',
        'side2Player2:id,name',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function presentMatch(PadelMatch $match, User $user): array
    {
        $match->loadMissing($this->matchRelations);
        $category = $match->phase->category;

        return [
            'id' => $match->id,
            'competition' => ['id' => $category->competition->id, 'name' => $category->competition->name],
            'category' => ['id' => $category->id, 'name' => $category->name],
            'phase' => $match->phase->name,
            'status' => $match->status,
            'side1' => [$match->side1Player1?->name, $match->side1Player2?->name],
            'side2' => [$match->side2Player1?->name, $match->side2Player2?->name],
            'my_side' => $match->sideOf($user),
            'winner_side' => $match->winner_side,
            'sets' => $match->matchSets->sortBy('set_number')
                ->map(fn ($set) => "{$set->side1_games}-{$set->side2_games}")->values()->all(),
            'booking' => [
                'scheduled_at' => $match->scheduled_at?->toIso8601String(),
                'club' => $match->club,
                'court' => $match->court,
                'playtomic_url' => $match->playtomic_url,
            ],
        ];
    }
}
