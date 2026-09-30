<?php

namespace App\Models\Concerns;

use App\Models\Category;
use App\Models\PadelMatch;
use App\Models\Pair;
use App\Models\Ranking;
use App\Models\User;

/**
 * Estadísticas públicas de un jugador, calculadas al vuelo a partir de sus
 * partidos terminados (a este tamaño no compensa cachearlas).
 */
trait HasPlayerStats
{
    /**
     * @return array{
     *     stats: array<string, int|null>,
     *     achievements: list<string>,
     *     usual_partner: array{id: int, name: string, matches: int}|null,
     * }
     */
    public function playerSummary(): array
    {
        $matches = PadelMatch::query()
            ->where('status', 'completed')
            ->forPlayer($this)
            ->with('matchSets')
            ->get()
            ->sortBy(fn (PadelMatch $match) => $match->scheduled_at ?? $match->updated_at);

        $wins = 0;
        $run = 0;
        $bestRun = 0;
        $setsWon = $setsLost = $gamesWon = $gamesLost = 0;
        $partners = [];

        foreach ($matches as $match) {
            $side = in_array($this->id, [$match->side1_player1_id, $match->side1_player2_id], true) ? 1 : 2;
            $won = $match->winner_side === $side;

            $wins += $won ? 1 : 0;
            $run = $won ? $run + 1 : 0;
            $bestRun = max($bestRun, $run);

            foreach ($match->matchSets as $set) {
                [$mine, $theirs] = $side === 1
                    ? [$set->side1_games, $set->side2_games]
                    : [$set->side2_games, $set->side1_games];

                $gamesWon += $mine;
                $gamesLost += $theirs;
                $setsWon += $mine > $theirs ? 1 : 0;
                $setsLost += $theirs > $mine ? 1 : 0;
            }

            $partnerId = $side === 1
                ? ($match->side1_player1_id === $this->id ? $match->side1_player2_id : $match->side1_player1_id)
                : ($match->side2_player1_id === $this->id ? $match->side2_player2_id : $match->side2_player1_id);
            $partners[$partnerId] = ($partners[$partnerId] ?? 0) + 1;
        }

        $played = $matches->count();

        return [
            'stats' => [
                'matches_played' => $played,
                'wins' => $wins,
                'losses' => $played - $wins,
                'win_rate' => $played > 0 ? (int) round($wins / $played * 100) : null,
                'current_streak' => $run,
                'best_streak' => $bestRun,
                'sets_won' => $setsWon,
                'sets_lost' => $setsLost,
                'games_won' => $gamesWon,
                'games_lost' => $gamesLost,
                'competitions_played' => $this->competitionsPlayed(),
            ],
            'achievements' => $this->achievements($played, $wins, $bestRun),
            'usual_partner' => $this->usualPartner($partners),
        ];
    }

    private function competitionsPlayed(): int
    {
        return Category::query()
            ->whereHas('registrations', fn ($query) => $query
                ->where('status', '!=', 'rejected')
                ->forUser($this))
            ->distinct()
            ->count('competition_id');
    }

    /**
     * Claves que la app traduce a texto e icono.
     *
     * @return list<string>
     */
    private function achievements(int $played, int $wins, int $bestRun): array
    {
        return array_keys(array_filter([
            'first_win' => $wins >= 1,
            'matches_10' => $played >= 10,
            'matches_50' => $played >= 50,
            'win_streak_5' => $bestRun >= 5,
            'champion' => $this->isChampion(),
        ]));
    }

    /**
     * Primero en la clasificación de una categoría de una competición ya
     * terminada y no cancelada.
     */
    private function isChampion(): bool
    {
        $pairIds = Pair::query()
            ->where(fn ($query) => $query->where('player1_id', $this->id)->orWhere('player2_id', $this->id))
            ->pluck('id');

        return Ranking::query()
            ->where('position', 1)
            ->where(fn ($query) => $query->where('player_id', $this->id)->orWhereIn('pair_id', $pairIds))
            ->whereHas('category.competition', fn ($query) => $query
                ->whereNotNull('end_date')
                ->where('end_date', '<', today())
                ->whereNull('cancelled_at'))
            ->exists();
    }

    /**
     * @param  array<int, int>  $partners  id de compañero => partidos juntos
     * @return array{id: int, name: string, matches: int}|null
     */
    private function usualPartner(array $partners): ?array
    {
        if ($partners === []) {
            return null;
        }

        arsort($partners);
        $partnerId = array_key_first($partners);

        // Con un solo partido juntos aún no es un "compañero habitual".
        if ($partners[$partnerId] < 2) {
            return null;
        }

        $partner = User::query()->find($partnerId, ['id', 'name']);

        return $partner ? ['id' => $partner->id, 'name' => $partner->name, 'matches' => $partners[$partnerId]] : null;
    }
}
