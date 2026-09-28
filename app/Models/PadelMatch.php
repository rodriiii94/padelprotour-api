<?php

namespace App\Models;

use Database\Factories\PadelMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'phase_id',
    'side1_player1_id',
    'side1_player2_id',
    'side2_player1_id',
    'side2_player2_id',
    'scheduled_at',
    'court',
    'status',
    'winner_side',
])]
class PadelMatch extends Model
{
    /** Horas que un resultado propuesto espera a un rival antes de confirmarse solo. */
    public const AUTO_CONFIRM_HOURS = 48;

    /** @use HasFactory<PadelMatchFactory> */
    use HasFactory;

    protected $table = 'matches';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'result_proposed_at' => 'datetime',
        ];
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    public function side1Player1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'side1_player1_id');
    }

    public function side1Player2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'side1_player2_id');
    }

    public function side2Player1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'side2_player1_id');
    }

    public function side2Player2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'side2_player2_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MatchMessage::class, 'match_id');
    }

    public function matchSets(): HasMany
    {
        return $this->hasMany(MatchSet::class, 'match_id');
    }

    /**
     * Cuántos sets registrados ha ganado ese lado.
     */
    public function setsWon(int $side): int
    {
        return $this->matchSets
            ->filter(fn (MatchSet $set) => $side === 1
                ? $set->side1_games > $set->side2_games
                : $set->side2_games > $set->side1_games)
            ->count();
    }

    /**
     * En pádel se juega al mejor de tres sets: el partido queda decidido
     * en cuanto un lado gana 2, sin necesidad de un tercero.
     */
    public function isDecided(): bool
    {
        return $this->setsWon(1) >= 2 || $this->setsWon(2) >= 2;
    }

    /**
     * Lado (1 o 2) en el que juega el usuario, o null si no juega este partido.
     */
    public function sideOf(User $user): ?int
    {
        return match (true) {
            in_array($user->id, [$this->side1_player1_id, $this->side1_player2_id], true) => 1,
            in_array($user->id, [$this->side2_player1_id, $this->side2_player2_id], true) => 2,
            default => null,
        };
    }

    /**
     * El partido con sus 4 jugadores en su resumen público (con avatar) en vez del resumen
     * mínimo (id, name) de la serialización por defecto -- para que el calendario pueda
     * mostrar la foto de perfil junto a cada nombre. Los 4 jugadores deben venir ya
     * cargados con las columnas de `User::SEARCH_COLUMNS` + `avatar_path`.
     *
     * @return array<string, mixed>
     */
    public function withPublicPlayers(): array
    {
        return [
            ...$this->toArray(),
            'side1_player1' => $this->side1Player1?->publicSummary(),
            'side1_player2' => $this->side1Player2?->publicSummary(),
            'side2_player1' => $this->side2Player1?->publicSummary(),
            'side2_player2' => $this->side2Player2?->publicSummary(),
        ];
    }
}
