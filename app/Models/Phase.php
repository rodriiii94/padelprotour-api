<?php

namespace App\Models;

use Database\Factories\PhaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Fillable(['category_id', 'type', 'name', 'order'])]
class Phase extends Model
{
    /** @use HasFactory<PhaseFactory> */
    use HasFactory;

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function matchdayPairings(): HasMany
    {
        return $this->hasMany(MatchdayPairing::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(PadelMatch::class);
    }

    /**
     * Genera el calendario de liga (round-robin) para una categoría de
     * pareja fija: una fase `matchday` por jornada, con los partidos de
     * esa jornada ya emparejados, usando el método del círculo (cada
     * pareja se enfrenta a todas las demás exactamente una vez). Si el
     * número de parejas es impar se añade un "bye": esa pareja descansa
     * esa jornada y no se crea partido para ella.
     *
     * @param  array<int, int>  $pairIds
     * @return Collection<int, Phase>
     */
    public static function generateRoundRobinForCategory(Category $category, array $pairIds): Collection
    {
        $pairs = Pair::whereIn('id', $pairIds)->get()->keyBy('id');
        $rounds = self::roundRobinRounds($pairIds);

        return DB::transaction(function () use ($category, $rounds, $pairs) {
            return collect($rounds)->values()->map(function (array $roundPairings, int $index) use ($category, $pairs) {
                $phase = $category->phases()->create([
                    'type' => 'matchday',
                    'name' => 'Jornada '.($index + 1),
                    'order' => $index + 1,
                ]);

                foreach ($roundPairings as [$pairAId, $pairBId]) {
                    if ($pairAId === null || $pairBId === null) {
                        continue;
                    }

                    $pairA = $pairs[$pairAId];
                    $pairB = $pairs[$pairBId];

                    $phase->matches()->create([
                        'side1_player1_id' => $pairA->player1_id,
                        'side1_player2_id' => $pairA->player2_id,
                        'side2_player1_id' => $pairB->player1_id,
                        'side2_player2_id' => $pairB->player2_id,
                        'status' => 'scheduled',
                    ]);
                }

                return $phase->load([
                    'matches.side1Player1:id,name',
                    'matches.side1Player2:id,name',
                    'matches.side2Player1:id,name',
                    'matches.side2Player2:id,name',
                ]);
            });
        });
    }

    /**
     * Método del círculo: fija el primer id y rota el resto en cada
     * ronda. Con N ids (par) produce N-1 rondas de N/2 emparejamientos
     * cada una, cubriendo todas las combinaciones posibles exactamente
     * una vez. Un número impar de ids se rellena con un `null` (bye).
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, array{0: int|null, 1: int|null}>>
     */
    private static function roundRobinRounds(array $ids): array
    {
        if (count($ids) % 2 !== 0) {
            $ids[] = null;
        }

        $count = count($ids);
        $rotating = array_values($ids);
        $rounds = [];

        for ($round = 0; $round < $count - 1; $round++) {
            $roundPairings = [];

            for ($i = 0; $i < $count / 2; $i++) {
                $roundPairings[] = [$rotating[$i], $rotating[$count - 1 - $i]];
            }

            $rounds[] = $roundPairings;

            $fixed = $rotating[0];
            $rest = array_slice($rotating, 1);
            array_unshift($rest, array_pop($rest));
            $rotating = array_merge([$fixed], $rest);
        }

        return $rounds;
    }
}
