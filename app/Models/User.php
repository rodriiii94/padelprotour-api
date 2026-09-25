<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasPlayerStats;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'level', 'club', 'provider', 'provider_id',
    'email_verification_token', 'email_verified_at',
    'bio', 'city', 'preferred_side', 'dominant_hand', 'avatar_color', 'avatar_emoji',
    'racket', 'motto', 'availability', 'social_links',
])]
#[Hidden(['password', 'remember_token', 'email_verification_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPlayerStats, Notifiable;

    public const NAME_CHANGE_INTERVAL_DAYS = 30;

    public const SOCIAL_NETWORKS = ['instagram', 'tiktok', 'x', 'youtube', 'facebook'];

    public const AVATAR_COLORS = ['lime', 'orange', 'sky', 'violet', 'rose', 'teal'];

    public const AVAILABILITY_DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const AVAILABILITY_PARTS = ['morning', 'afternoon', 'evening'];

    /**
     * Lo que puede ver cualquiera de otro jugador. Nunca el email ni los
     * datos de login social.
     */
    public const PUBLIC_COLUMNS = [
        'id', 'name', 'level', 'club', 'city', 'bio', 'preferred_side', 'dominant_hand',
        'avatar_color', 'avatar_emoji', 'racket', 'motto', 'availability', 'social_links', 'created_at',
    ];

    /** Resumen para listas y buscadores. */
    public const SEARCH_COLUMNS = ['id', 'name', 'level', 'club', 'city', 'avatar_color', 'avatar_emoji'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'name_changed_at' => 'datetime',
            'password' => 'hashed',
            'availability' => 'array',
            'social_links' => 'array',
        ];
    }

    /**
     * Cuándo podrá volver a cambiar su nombre; null si ya puede.
     *
     * @return Attribute<Carbon|null, never>
     */
    protected function nameChangeAvailableAt(): Attribute
    {
        return Attribute::get(function (): ?Carbon {
            $next = $this->name_changed_at?->copy()->addDays(self::NAME_CHANGE_INTERVAL_DAYS);

            return $next?->isFuture() ? $next : null;
        })->withoutObjectCaching();
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

    /**
     * Competiciones que el usuario organiza y siguen vivas (ni canceladas ni terminadas).
     * Mientras existan, la cuenta no se puede eliminar: se quedarían sin organizador.
     */
    public function hasActiveOrganizedCompetitions(): bool
    {
        return Competition::query()
            ->where('organizer_id', $this->id)
            ->whereNull('cancelled_at')
            ->upcoming()
            ->exists();
    }

    /**
     * Elimina la cuenta conservando el historial de los demás: los partidos, parejas y
     * resultados siguen existiendo y muestran a "Jugador eliminado". Se borran los datos
     * personales, el acceso y las inscripciones que aún no estaban confirmadas.
     */
    public function anonymize(): void
    {
        DB::transaction(function (): void {
            $this->tokens()->delete();

            Registration::query()
                ->forUser($this)
                ->where('status', '!=', 'confirmed')
                ->delete();

            $this->forceFill([
                'name' => 'Jugador eliminado',
                'email' => "eliminado-{$this->id}@deleted.invalid",
                'password' => null,
                'remember_token' => null,
                'provider' => null,
                'provider_id' => null,
                'email_verification_token' => null,
                'email_verified_at' => null,
                'name_changed_at' => null,
                'level' => null,
                'club' => null,
                'bio' => null,
                'city' => null,
                'preferred_side' => null,
                'dominant_hand' => null,
                'avatar_color' => null,
                'avatar_emoji' => null,
                'racket' => null,
                'motto' => null,
                'availability' => null,
                'social_links' => null,
            ])->save();
        });
    }
}
