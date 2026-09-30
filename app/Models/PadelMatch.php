<?php

namespace App\Models;

use Database\Factories\PadelMatchFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'phase_id',
    'side1_player1_id',
    'side1_player2_id',
    'side2_player1_id',
    'side2_player2_id',
    'scheduled_at',
    'court',
    'club',
    'playtomic_url',
    'status',
    'winner_side',
])]
class PadelMatch extends Model
{
    /** Horas que un resultado propuesto espera a un rival antes de confirmarse solo. */
    public const AUTO_CONFIRM_HOURS = 48;

    /** Forma de los sets al proponer un resultado (la API y el MCP validan lo mismo). */
    public const PROPOSAL_RULES = [
        'sets' => ['required', 'array', 'min:2', 'max:3'],
        'sets.*.side1_games' => ['required', 'integer', 'min:0'],
        'sets.*.side2_games' => ['required', 'integer', 'min:0'],
    ];

    /** Reserva de pista: solo enlaces https de Playtomic (p. ej. `app.playtomic.com/t/...`). */
    public const BOOKING_RULES = [
        'scheduled_at' => ['nullable', 'date'],
        'club' => ['nullable', 'string', 'max:120'],
        'court' => ['nullable', 'string', 'max:255'],
        'playtomic_url' => ['nullable', 'string', 'max:255', 'url:https', 'regex:/^https:\/\/([a-z0-9-]+\.)*playtomic\.(com|io)(\/\S*)?$/i'],
    ];

    public const BOOKING_MESSAGES = [
        'playtomic_url.url' => 'El enlace debe ser un enlace de Playtomic.',
        'playtomic_url.regex' => 'El enlace debe ser un enlace de Playtomic.',
    ];

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

    /**
     * Partidos en los que juega el usuario, en cualquiera de los cuatro huecos.
     */
    #[Scope]
    protected function forPlayer(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('side1_player1_id', $user->id)
            ->orWhere('side1_player2_id', $user->id)
            ->orWhere('side2_player1_id', $user->id)
            ->orWhere('side2_player2_id', $user->id));
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

    /**
     * Uno de los cuatro jugadores propone los sets: el partido queda en `pending_validation`
     * hasta que un rival (o el organizador) lo confirme.
     *
     * @param  array<int, array{side1_games: int, side2_games: int}>  $sets
     *
     * @throws AuthorizationException|ValidationException
     */
    public function proposeResult(User $user, array $sets): void
    {
        if ($this->sideOf($user) === null) {
            throw new AuthorizationException('Solo los jugadores del partido pueden proponer el resultado.');
        }

        $this->ensureCompetitionNotCancelled();

        if (in_array($this->status, ['completed', 'pending_validation'], true)) {
            throw ValidationException::withMessages(['match' => [$this->status === 'completed'
                ? 'El partido ya tiene un resultado validado.'
                : 'Ya hay un resultado pendiente de validar.']]);
        }

        $winnerSide = self::winnerOf($sets);

        DB::transaction(function () use ($sets, $winnerSide, $user): void {
            $this->matchSets()->delete();

            foreach (array_values($sets) as $index => $set) {
                $this->matchSets()->create([
                    'set_number' => $index + 1,
                    'side1_games' => $set['side1_games'],
                    'side2_games' => $set['side2_games'],
                ]);
            }

            $this->forceFill([
                'status' => 'pending_validation',
                'winner_side' => $winnerSide,
                'result_proposed_by' => $user->id,
                'result_proposed_at' => now(),
            ])->save();
        });
    }

    /**
     * Guarda la reserva de pista ya validada con `BOOKING_RULES`. La hora se guarda en UTC.
     *
     * @param  array{scheduled_at?: ?string, club?: ?string, court?: ?string, playtomic_url?: ?string}  $booking
     *
     * @throws ValidationException
     */
    public function updateBooking(array $booking): void
    {
        $this->ensureCompetitionNotCancelled();

        if (isset($booking['scheduled_at'])) {
            $booking['scheduled_at'] = Carbon::parse($booking['scheduled_at'])->utc();
        }

        $this->update($booking);
    }

    /**
     * @throws ValidationException
     */
    private function ensureCompetitionNotCancelled(): void
    {
        if ($this->phase->category->competition->cancelled_at !== null) {
            throw ValidationException::withMessages(['match' => ['Esta competición ha sido cancelada.']]);
        }
    }

    /**
     * Ganador (1 o 2) de los sets propuestos. El partido es al mejor de tres: debe estar
     * decidido exactamente al final y no puede seguir después de decidirse.
     *
     * @param  array<int, array{side1_games: int, side2_games: int}>  $sets
     *
     * @throws ValidationException
     */
    private static function winnerOf(array $sets): int
    {
        $won = [1 => 0, 2 => 0];

        foreach (array_values($sets) as $set) {
            if (! MatchSet::isValidScore($set['side1_games'], $set['side2_games'])) {
                throw ValidationException::withMessages([
                    'sets' => ['Resultado de set no válido: en pádel se gana con 6 juegos y 2 de diferencia, 7-5, o 7-6 en tie-break.'],
                ]);
            }

            if ($won[1] >= 2 || $won[2] >= 2) {
                throw ValidationException::withMessages(['sets' => ['El partido ya estaba decidido antes del último set.']]);
            }

            $won[$set['side1_games'] > $set['side2_games'] ? 1 : 2]++;
        }

        if ($won[1] < 2 && $won[2] < 2) {
            throw ValidationException::withMessages(['sets' => ['El resultado no decide el partido: un lado debe ganar 2 sets.']]);
        }

        return $won[1] >= 2 ? 1 : 2;
    }
}
