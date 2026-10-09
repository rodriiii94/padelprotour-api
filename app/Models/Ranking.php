<?php

namespace App\Models;

use Database\Factories\RankingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'category_id', 'pair_id', 'player_id', 'points', 'position', 'calculated_at',
    'played', 'won', 'lost', 'sets_won', 'sets_lost', 'games_won', 'games_lost',
])]
class Ranking extends Model
{
    /** @use HasFactory<RankingFactory> */
    use HasFactory;

    /** Estadísticas que se guardan con cada fila de la clasificación. */
    private const STAT_KEYS = ['points', 'played', 'won', 'lost', 'sets_won', 'sets_lost', 'games_won', 'games_lost'];

    /**
     * Recalcula la clasificación de una categoría desde cero: victoria = 3
     * puntos, derrota = 0. Solo cuentan partidos `completed` de fases
     * `group`/`matchday` (las eliminatorias no reparten puntos de liga).
     * Desempate: enfrentamiento directo (solo si son 2 empatados) ->
     * diferencia de sets -> diferencia de juegos -> juegos ganados.
     *
     * Sustituye por completo las filas anteriores de la categoría (foto,
     * no acumulación) con un `calculated_at` nuevo.
     *
     * @return Collection<int, Ranking>
     */
    public static function recalculateForCategory(Category $category): Collection
    {
        $usesPlayer = $category->registration_mode === 'individual_rotating';

        $matches = PadelMatch::query()
            ->whereHas('phase', fn ($query) => $query->where('category_id', $category->id)->whereIn('type', ['group', 'matchday']))
            ->where('status', 'completed')
            ->with('matchSets')
            ->get();

        $stats = self::zeroStatsForRegistered($category, $usesPlayer);
        $headToHead = [];
        $pairCache = [];

        foreach ($matches as $match) {
            $side1Keys = self::sideKeys($match, 1, $usesPlayer, $pairCache);
            $side2Keys = self::sideKeys($match, 2, $usesPlayer, $pairCache);

            // Lado sin pareja registrada identificable: se omite del cálculo
            // (ver limitación documentada en el plan de implementación).
            if ($side1Keys === [] || $side2Keys === []) {
                continue;
            }

            $side1Won = $match->winner_side === 1;
            $side1Sets = $match->matchSets->filter(fn ($set) => $set->side1_games > $set->side2_games)->count();
            $side2Sets = $match->matchSets->filter(fn ($set) => $set->side2_games > $set->side1_games)->count();
            $side1Games = (int) $match->matchSets->sum('side1_games');
            $side2Games = (int) $match->matchSets->sum('side2_games');

            foreach ($side1Keys as $key) {
                self::addStats($stats, $key, $side1Won, $side1Sets, $side2Sets, $side1Games, $side2Games);
            }

            foreach ($side2Keys as $key) {
                self::addStats($stats, $key, ! $side1Won, $side2Sets, $side1Sets, $side2Games, $side1Games);
            }

            foreach ($side1Keys as $a) {
                foreach ($side2Keys as $b) {
                    self::recordHeadToHead($headToHead, $a, $b, $side1Won);
                }
            }
        }

        $ordered = self::orderEntities($stats, $headToHead);
        $calculatedAt = now();

        return DB::transaction(function () use ($category, $ordered, $calculatedAt, $usesPlayer) {
            $category->rankings()->delete();

            return collect($ordered)->values()->map(fn (array $entry, int $index) => $category->rankings()->create([
                'pair_id' => $usesPlayer ? null : $entry['id'],
                'player_id' => $usesPlayer ? $entry['id'] : null,
                ...Arr::only($entry, self::STAT_KEYS),
                'position' => $index + 1,
                'calculated_at' => $calculatedAt,
            ]));
        });
    }

    /**
     * @return array<int, int>
     */
    private static function sideKeys(PadelMatch $match, int $side, bool $usesPlayer, array &$pairCache): array
    {
        $player1 = $side === 1 ? $match->side1_player1_id : $match->side2_player1_id;
        $player2 = $side === 1 ? $match->side1_player2_id : $match->side2_player2_id;

        if ($usesPlayer) {
            return [$player1, $player2];
        }

        $pairId = self::resolvePairId($player1, $player2, $pairCache);

        return $pairId === null ? [] : [$pairId];
    }

    /**
     * @param  array<string, int|null>  $cache
     */
    private static function resolvePairId(int $player1, int $player2, array &$cache): ?int
    {
        $cacheKey = min($player1, $player2).'-'.max($player1, $player2);

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $pair = Pair::query()
            ->where(fn ($query) => $query->where('player1_id', $player1)->where('player2_id', $player2))
            ->orWhere(fn ($query) => $query->where('player1_id', $player2)->where('player2_id', $player1))
            ->first();

        return $cache[$cacheKey] = $pair?->id;
    }

    /**
     * Los inscritos confirmados de la categoría, todos a cero: así salen en la clasificación
     * aunque todavía no hayan jugado.
     *
     * @return array<int, array<string, int>>
     */
    private static function zeroStatsForRegistered(Category $category, bool $usesPlayer): array
    {
        return $category->registrations()
            ->where('status', 'confirmed')
            ->whereNotNull($usesPlayer ? 'player_id' : 'pair_id')
            ->orderBy('id')
            ->pluck($usesPlayer ? 'player_id' : 'pair_id')
            ->mapWithKeys(fn (int $id) => [$id => array_fill_keys(self::STAT_KEYS, 0)])
            ->all();
    }

    /**
     * Suma un partido a las estadísticas de una pareja o jugador. Victoria = 3 puntos.
     *
     * @param  array<int, array<string, int>>  $stats
     */
    private static function addStats(array &$stats, int $key, bool $won, int $setsWon, int $setsLost, int $gamesWon, int $gamesLost): void
    {
        $stats[$key] ??= array_fill_keys(self::STAT_KEYS, 0);
        $stats[$key]['points'] += $won ? 3 : 0;
        $stats[$key]['played']++;
        $stats[$key][$won ? 'won' : 'lost']++;
        $stats[$key]['sets_won'] += $setsWon;
        $stats[$key]['sets_lost'] += $setsLost;
        $stats[$key]['games_won'] += $gamesWon;
        $stats[$key]['games_lost'] += $gamesLost;
    }

    /**
     * @param  array<int, array<int, array{wins: int, losses: int}>>  $headToHead
     */
    private static function recordHeadToHead(array &$headToHead, int $a, int $b, bool $aWon): void
    {
        $headToHead[$a][$b]['wins'] = ($headToHead[$a][$b]['wins'] ?? 0) + ($aWon ? 1 : 0);
        $headToHead[$a][$b]['losses'] = ($headToHead[$a][$b]['losses'] ?? 0) + ($aWon ? 0 : 1);
        $headToHead[$b][$a]['wins'] = ($headToHead[$b][$a]['wins'] ?? 0) + ($aWon ? 0 : 1);
        $headToHead[$b][$a]['losses'] = ($headToHead[$b][$a]['losses'] ?? 0) + ($aWon ? 1 : 0);
    }

    /**
     * @param  array<int, array<string, int>>  $stats
     * @param  array<int, array<int, array{wins: int, losses: int}>>  $headToHead
     * @return list<array<string, int>> cada entrada lleva `id` además de las estadísticas
     */
    private static function orderEntities(array $stats, array $headToHead): array
    {
        $entities = [];

        foreach ($stats as $id => $entry) {
            $entities[] = [...$entry, 'id' => $id];
        }

        $tieGroupSizes = collect($entities)->countBy('points');

        usort($entities, function (array $a, array $b) use ($headToHead, $tieGroupSizes) {
            if ($a['points'] !== $b['points']) {
                return $b['points'] <=> $a['points'];
            }

            if ($tieGroupSizes[$a['points']] === 2 && isset($headToHead[$a['id']][$b['id']])) {
                $record = $headToHead[$a['id']][$b['id']];
                if ($record['wins'] !== $record['losses']) {
                    return $record['losses'] <=> $record['wins'];
                }
            }

            $setDiffA = $a['sets_won'] - $a['sets_lost'];
            $setDiffB = $b['sets_won'] - $b['sets_lost'];
            if ($setDiffA !== $setDiffB) {
                return $setDiffB <=> $setDiffA;
            }

            $gameDiffA = $a['games_won'] - $a['games_lost'];
            $gameDiffB = $b['games_won'] - $b['games_lost'];
            if ($gameDiffA !== $gameDiffB) {
                return $gameDiffB <=> $gameDiffA;
            }

            return $b['games_won'] <=> $a['games_won'];
        });

        return $entities;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calculated_at' => 'datetime',
        ];
    }

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
}
