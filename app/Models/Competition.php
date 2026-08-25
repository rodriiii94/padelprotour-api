<?php

namespace App\Models;

use Database\Factories\CompetitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['type', 'name', 'venue', 'start_date', 'end_date', 'organizer_id', 'registration_closes_at'])]
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
}
