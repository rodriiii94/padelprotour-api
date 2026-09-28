<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Phase;

class RoundRobinController extends Controller
{
    public function generate(Category $category)
    {
        $this->authorize('update', $category->competition);

        abort_unless(
            $category->competition->type === 'league',
            422,
            'Solo las categorías de una competición de tipo liga admiten generación de calendario.'
        );

        abort_unless(
            in_array($category->registration_mode, [null, 'fixed_pair'], true),
            422,
            'La generación automática de calendario es solo para categorías de pareja fija.'
        );

        abort_if(
            $category->phases()->where('type', 'matchday')->exists(),
            422,
            'Esta categoría ya tiene jornadas generadas.'
        );

        $pairIds = $category->registrations()
            ->where('status', 'confirmed')
            ->whereNotNull('pair_id')
            ->pluck('pair_id')
            ->all();

        abort_if(count($pairIds) < 2, 422, 'Hacen falta al menos 2 parejas confirmadas para generar el calendario.');

        $phases = Phase::generateRoundRobinForCategory($category, $pairIds, $category->competition->double_round);

        return response()->json(
            $phases->map(fn (Phase $phase) => [
                ...$phase->toArray(),
                'matches' => $phase->matches->map->withPublicPlayers(),
            ]),
            201,
        );
    }
}
