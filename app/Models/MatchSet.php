<?php

namespace App\Models;

use Database\Factories\MatchSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['match_id', 'set_number', 'side1_games', 'side2_games'])]
class MatchSet extends Model
{
    /** @use HasFactory<MatchSetFactory> */
    use HasFactory;

    public function match(): BelongsTo
    {
        return $this->belongsTo(PadelMatch::class, 'match_id');
    }

    /**
     * Un set de pádel válido termina en 6 juegos con 2 de diferencia
     * (6-0 .. 6-4), se alarga a 7-5, o llega a tie-break y se cierra 7-6.
     * Nada por debajo de esos finales ni por encima de 7 juegos es válido.
     */
    public static function isValidScore(int $side1Games, int $side2Games): bool
    {
        $winnerGames = max($side1Games, $side2Games);
        $loserGames = min($side1Games, $side2Games);

        return match ($winnerGames) {
            6 => $loserGames <= 4,
            7 => in_array($loserGames, [5, 6], true),
            default => false,
        };
    }
}
