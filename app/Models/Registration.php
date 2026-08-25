<?php

namespace App\Models;

use Database\Factories\RegistrationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['category_id', 'pair_id', 'player_id', 'status'])]
class Registration extends Model
{
    /** @use HasFactory<RegistrationFactory> */
    use HasFactory;

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function pair(): BelongsTo
    {
        return $this->belongsTo(Pair::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Inscripciones del usuario, ya sea directamente (jugador individual)
     * o a través de una pareja de la que forme parte.
     */
    #[Scope]
    protected function forUser(Builder $query, User $user): Builder
    {
        // Envuelto en un único where() para que el OR interno quede
        // agrupado y se pueda combinar con seguridad con otros where()
        // encadenados por quien use este scope (p.ej. un filtro de status).
        return $query->where(fn (Builder $query) => $query->where('player_id', $user->id)
            ->orWhereHas('pair', fn (Builder $query) => $query->where('player1_id', $user->id)->orWhere('player2_id', $user->id))
        );
    }
}
