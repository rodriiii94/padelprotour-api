<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'level', 'club'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function pairsAsPlayer1(): HasMany
    {
        return $this->hasMany(Pair::class, 'player1_id');
    }

    public function pairsAsPlayer2(): HasMany
    {
        return $this->hasMany(Pair::class, 'player2_id');
    }

    public function organizedCompetitions(): HasMany
    {
        return $this->hasMany(Competition::class, 'organizer_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class, 'player_id');
    }

    public function rankings(): HasMany
    {
        return $this->hasMany(Ranking::class, 'player_id');
    }

    public function matchdayPairingsAsPlayer1(): HasMany
    {
        return $this->hasMany(MatchdayPairing::class, 'player1_id');
    }

    public function matchdayPairingsAsPlayer2(): HasMany
    {
        return $this->hasMany(MatchdayPairing::class, 'player2_id');
    }

    public function matchesAsSide1Player1(): HasMany
    {
        return $this->hasMany(PadelMatch::class, 'side1_player1_id');
    }

    public function matchesAsSide1Player2(): HasMany
    {
        return $this->hasMany(PadelMatch::class, 'side1_player2_id');
    }

    public function matchesAsSide2Player1(): HasMany
    {
        return $this->hasMany(PadelMatch::class, 'side2_player1_id');
    }

    public function matchesAsSide2Player2(): HasMany
    {
        return $this->hasMany(PadelMatch::class, 'side2_player2_id');
    }

    public function authoredChatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'author_id');
    }
}
