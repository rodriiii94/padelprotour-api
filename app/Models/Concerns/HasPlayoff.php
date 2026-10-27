<?php

namespace App\Models\Concerns;

use App\Models\MatchSet;
use App\Models\PadelMatch;
use App\Models\Pair;
use App\Models\Phase;
use App\Models\Ranking;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Playoff opcional al final de una liga de pareja fija: eliminatorias a partido único entre
 * las mejores de la clasificación. Cada ronda es una fase `elimination_round` (sus partidos no
 * suman puntos de liga) y cada partido guarda su hueco en la ronda (`bracket_position`).
 */
trait HasPlayoff
{
    public const PLAYOFF_PHASE_TYPE = 'elimination_round';

    /** Parejas que pueden entrar al playoff. */
    public const PLAYOFF_SIZES = [4, 8];

    /**
     * Cabezas de serie de cada hueco de la primera ronda, de dos en dos: así el 1 y el 2 solo
     * pueden cruzarse en la final.
     */
    private const PLAYOFF_SEEDING = [
        4 => [1, 4, 2, 3],
        8 => [1, 8, 4, 5, 2, 7, 3, 6],
    ];

    /** Nombre de cada ronda según los partidos que tiene. */
    private const PLAYOFF_ROUND_NAMES = [4 => 'Cuartos de final', 2 => 'Semifinales', 1 => 'Final'];

    /** Nombre de un partido de esa ronda, para "Ganador de Semifinal 2". */
    private const PLAYOFF_MATCH_NAMES = [4 => 'Cuarto de final', 2 => 'Semifinal', 1 => 'Final'];

    public function hasPlayoff(): bool
    {
        return $this->playoffPhases()->isNotEmpty();
    }

    /**
     * @return Collection<int, Phase>
     */
    public function playoffPhases(): Collection
    {
        return $this->phases()->where('type', self::PLAYOFF_PHASE_TYPE)->orderBy('order')->get();
    }

    /**
     * Crea el cuadro con las `$size` primeras de la clasificación final (todos los partidos de
     * liga deben tener resultado confirmado) y los partidos de la primera ronda. Las rondas siguientes se crean vacías y se van llenando con los ganadores.
     *
     * @throws ValidationException
     */
    public function createPlayoff(int $size): void
    {
        if (! in_array($this->registration_mode, [null, 'fixed_pair'], true)) {
            throw ValidationException::withMessages(['playoff' => ['El playoff es solo para categorías de pareja fija.']]);
        }

        if ($this->hasPlayoff()) {
            throw ValidationException::withMessages(['playoff' => ['Esta categoría ya tiene playoff.']]);
        }

        // Solo con la liga terminada: los cruces salen de la clasificación final, que no puede
        // cambiar después (un partido sin jugar o pendiente de validar aún podría moverla).
        $unfinished = PadelMatch::query()
            ->whereHas('phase', fn ($query) => $query
                ->where('category_id', $this->id)
                ->where('type', '!=', self::PLAYOFF_PHASE_TYPE))
            ->where('status', '!=', 'completed')
            ->count();

        if ($unfinished > 0) {
            throw ValidationException::withMessages(['playoff' => [$unfinished === 1
                ? 'La liga no ha terminado: queda 1 partido sin resultado confirmado.'
                : "La liga no ha terminado: quedan {$unfinished} partidos sin resultado confirmado.",
            ]]);
        }

        $seededPairIds = Ranking::recalculateForCategory($this)->sortBy('position')->pluck('pair_id')->filter()->values();

        if ($seededPairIds->count() < $size) {
            throw ValidationException::withMessages(['size' => [
                "Hacen falta al menos {$size} parejas en la clasificación (hay {$seededPairIds->count()}).",
            ]]);
        }

        $pairs = Pair::query()->whereKey($seededPairIds->take($size))->get()->keyBy('id');
        $firstOrder = (int) $this->phases()->max('order') + 1;

        DB::transaction(function () use ($size, $seededPairIds, $pairs, $firstOrder): void {
            $matchesInRound = intdiv($size, 2);
            $order = $firstOrder;
            $firstRound = null;

            while ($matchesInRound >= 1) {
                $phase = $this->phases()->create([
                    'type' => self::PLAYOFF_PHASE_TYPE,
                    'name' => self::PLAYOFF_ROUND_NAMES[$matchesInRound],
                    'order' => $order++,
                ]);
                $firstRound ??= $phase;
                $matchesInRound = intdiv($matchesInRound, 2);
            }

            foreach (array_chunk(self::PLAYOFF_SEEDING[$size], 2) as $position => [$seed1, $seed2]) {
                $this->createPlayoffMatch(
                    $firstRound,
                    $position,
                    $pairs[$seededPairIds[$seed1 - 1]],
                    $pairs[$seededPairIds[$seed2 - 1]],
                );
            }
        });
    }

    /**
     * Borra el playoff mientras ningún partido tenga resultado (para corregir un error al crearlo).
     *
     * @throws ValidationException
     */
    public function deletePlayoff(): void
    {
        $phaseIds = $this->playoffPhases()->pluck('id');

        if (PadelMatch::query()->whereIn('phase_id', $phaseIds)->where('status', '!=', 'scheduled')->exists()) {
            throw ValidationException::withMessages(['playoff' => ['El playoff ya tiene resultados: no se puede borrar.']]);
        }

        Phase::query()->whereKey($phaseIds)->delete();
    }

    /**
     * Si el otro cruce de la misma eliminatoria ya está decidido, crea el partido de la ronda
     * siguiente con los dos ganadores (el del hueco par, en el lado 1). Idempotente.
     */
    public function advancePlayoff(PadelMatch $match): void
    {
        $nextPhase = $this->phases()
            ->where('type', self::PLAYOFF_PHASE_TYPE)
            ->where('order', '>', $match->phase->order)
            ->orderBy('order')
            ->first();

        if ($nextPhase === null || $match->bracket_position === null) {
            return;
        }

        $siblingPosition = $match->bracket_position ^ 1;
        $sibling = $match->phase->matches()->where('bracket_position', $siblingPosition)->first();

        if ($sibling?->status !== 'completed') {
            return;
        }

        [$first, $second] = $match->bracket_position < $siblingPosition ? [$match, $sibling] : [$sibling, $match];

        try {
            // Transacción propia (un savepoint si ya hay una abierta): en PostgreSQL, un choque
            // con el índice único dejaría abortada la transacción de quien llama.
            DB::transaction(fn () => $this->createPlayoffMatch(
                $nextPhase,
                intdiv($match->bracket_position, 2),
                $first->winningPair(),
                $second->winningPair(),
            ));
        } catch (UniqueConstraintViolationException) {
            // El otro resultado se confirmó a la vez y ya creó este cruce.
        }
    }

    /**
     * El cuadro completo para pintarlo: cada ronda con todos sus huecos, los aún sin partido
     * con el texto de quién llegará ("Ganador de Semifinal 1"), y el campeón si ya lo hay.
     *
     * @return array{size: int, champion: array{pair_id: int, name: string}|null, rounds: list<array<string, mixed>>}
     */
    public function playoffBracket(): array
    {
        $phases = $this->playoffPhases()->load([
            'matches.matchSets',
            'matches.side1Player1:id,name', 'matches.side1Player2:id,name',
            'matches.side2Player1:id,name', 'matches.side2Player2:id,name',
        ]);
        $size = 2 ** $phases->count();
        $seeds = $this->playoffSeeds($phases->first(), $size);
        $previous = null;
        $rounds = [];

        foreach ($phases as $phase) {
            $slots = intdiv($size, 2 ** (count($rounds) + 1));
            $matches = $phase->matches->keyBy('bracket_position');
            $round = ['phase_id' => $phase->id, 'name' => $phase->name, 'matches' => []];

            for ($position = 0; $position < $slots; $position++) {
                $match = $matches[$position] ?? null;
                $round['matches'][] = [
                    'position' => $position,
                    'match_id' => $match?->id,
                    'status' => $match?->status,
                    'winner_side' => $match?->status === 'completed' ? $match->winner_side : null,
                    'scheduled_at' => $match?->scheduled_at,
                    'score' => $match ? $this->playoffScore($match) : null,
                    'sides' => $match
                        ? [$this->playoffSide($match->sidePair(1), $seeds), $this->playoffSide($match->sidePair(2), $seeds)]
                        : [
                            $this->feederSide($previous, $position * 2, $seeds),
                            $this->feederSide($previous, $position * 2 + 1, $seeds),
                        ],
                ];
            }

            $rounds[] = $round;
            $previous = $round;
        }

        $final = end($rounds)['matches'][0] ?? null;
        $champion = $final && $final['winner_side'] ? $final['sides'][$final['winner_side'] - 1] : null;

        return [
            'size' => $size,
            'champion' => $champion ? ['pair_id' => $champion['pair_id'], 'name' => $champion['name']] : null,
            'rounds' => $rounds,
        ];
    }

    private function createPlayoffMatch(Phase $phase, int $position, Pair $side1, Pair $side2): PadelMatch
    {
        return $phase->matches()->create([
            'bracket_position' => $position,
            'side1_player1_id' => $side1->player1_id,
            'side1_player2_id' => $side1->player2_id,
            'side2_player1_id' => $side2->player1_id,
            'side2_player2_id' => $side2->player2_id,
            'status' => 'scheduled',
        ]);
    }

    /**
     * Cabeza de serie de cada pareja, según su hueco en la primera ronda.
     *
     * @return array<int, int> id de pareja => cabeza de serie
     */
    private function playoffSeeds(Phase $firstRound, int $size): array
    {
        $seeds = [];

        foreach ($firstRound->matches as $match) {
            $seeds[$match->sidePair(1)?->id] = self::PLAYOFF_SEEDING[$size][$match->bracket_position * 2];
            $seeds[$match->sidePair(2)?->id] = self::PLAYOFF_SEEDING[$size][$match->bracket_position * 2 + 1];
        }

        return $seeds;
    }

    /**
     * @param  array<int, int>  $seeds
     * @return array{seed: int|null, pair_id: int|null, name: string}
     */
    private function playoffSide(?Pair $pair, array $seeds): array
    {
        return [
            'seed' => $pair ? ($seeds[$pair->id] ?? null) : null,
            'pair_id' => $pair?->id,
            'name' => $pair?->displayName() ?? 'Pareja desconocida',
        ];
    }

    /**
     * Hueco de un partido que aún no existe: si su cruce de origen ya tiene ganador, esa pareja;
     * si no, "Ganador de …".
     *
     * @param  array<string, mixed>|null  $previousRound
     * @param  array<int, int>  $seeds
     * @return array<string, mixed>
     */
    private function feederSide(?array $previousRound, int $feederPosition, array $seeds): array
    {
        $feeder = $previousRound['matches'][$feederPosition] ?? null;

        if ($feeder && $feeder['winner_side']) {
            return $feeder['sides'][$feeder['winner_side'] - 1];
        }

        $matchesInRound = count($previousRound['matches'] ?? []);
        $matchName = self::PLAYOFF_MATCH_NAMES[$matchesInRound] ?? 'la ronda anterior';
        $label = $matchesInRound > 1 ? "{$matchName} ".($feederPosition + 1) : $matchName;

        return ['placeholder' => "Ganador de {$label}"];
    }

    private function playoffScore(PadelMatch $match): ?string
    {
        $score = $match->matchSets->sortBy('set_number')
            ->map(fn (MatchSet $set) => "{$set->side1_games}-{$set->side2_games}")
            ->implode(' ');

        return $score === '' ? null : $score;
    }
}
