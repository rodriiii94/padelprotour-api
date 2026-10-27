<?php

namespace App\Models;

use Database\Factories\PairFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['player1_id', 'player2_id', 'name'])]
class Pair extends Model
{
    /** @use HasFactory<PairFactory> */
    use HasFactory;

    /**
     * La pareja que forman esos dos jugadores, en cualquier orden.
     */
    public static function forPlayers(int $playerA, int $playerB): ?self
    {
        return self::query()
            ->where(fn ($query) => $query->where('player1_id', $playerA)->where('player2_id', $playerB))
            ->orWhere(fn ($query) => $query->where('player1_id', $playerB)->where('player2_id', $playerA))
            ->first();
    }

    /**
     * Su nombre si lo tiene, o "Jugador 1 / Jugador 2".
     */
    public function displayName(): string
    {
        return $this->name ?: collect([$this->player1?->name, $this->player2?->name])->filter()->implode(' / ');
    }

    public function player1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player1_id');
    }

    public function player2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player2_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function rankings(): HasMany
    {
        return $this->hasMany(Ranking::class);
    }
}
