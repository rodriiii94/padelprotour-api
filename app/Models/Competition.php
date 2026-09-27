<?php

namespace App\Models;

use Database\Factories\CompetitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'name', 'venue', 'start_date', 'end_date', 'organizer_id', 'registration_closes_at', 'is_private', 'invite_token', 'double_round'])]
#[Hidden(['invite_token'])]
class Competition extends Model
{
    /** @use HasFactory<CompetitionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'registration_closes_at' => 'datetime',
            'is_private' => 'boolean',
            'double_round' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    /**
     * El organizador, o cualquier usuario con una inscripción no
     * rechazada (como pareja o individual) en alguna categoría de la
     * competición.
     */
    public function hasParticipant(User $user): bool
    {
        if ($this->organizer_id === $user->id) {
            return true;
        }

        return Registration::query()
            ->whereHas('category', fn ($query) => $query->where('competition_id', $this->id))
            ->where('status', '!=', 'rejected')
            ->where(fn ($query) => $query->where('player_id', $user->id)
                ->orWhereHas('pair', fn ($query) => $query->where('player1_id', $user->id)->orWhere('player2_id', $user->id))
            )
            ->exists();
    }

    /**
     * `invite_token` está oculto por defecto (#[Hidden]): solo el
     * organizador necesita verlo para poder compartirlo.
     */
    public function revealInviteTokenFor(?User $user): static
    {
        if ($user && $user->id === $this->organizer_id) {
            $this->makeVisible('invite_token');
        }

        return $this;
    }

    /**
     * Competiciones del usuario: las que organiza, o en las que tiene
     * una inscripción no rechazada en alguna categoría.
     */
    #[Scope]
    protected function relatedTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query->where('organizer_id', $user->id)
            ->orWhereHas('categories.registrations', fn (Builder $query) => $query
                ->where('status', '!=', 'rejected')
                ->forUser($user)
            )
        );
    }

    /**
     * No finalizadas: sin `end_date`, o con `end_date` de hoy en adelante.
     */
    #[Scope]
    protected function upcoming(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query->whereNull('end_date')
            ->orWhere('end_date', '>=', today())
        );
    }
}
