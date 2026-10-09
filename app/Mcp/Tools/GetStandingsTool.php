<?php

namespace App\Mcp\Tools;

use App\Models\Category;
use App\Models\Ranking;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('get_standings')]
#[Description('Clasificación de una categoría: posición, pareja (o jugador en ligas de rotación), puntos, estadísticas (partidos jugados/ganados/perdidos, sets y juegos a favor y en contra) y racha de los últimos 5 partidos (W ganado, L perdido, del más antiguo al más reciente). Victoria = 3 puntos.')]
class GetStandingsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['category_id' => ['required', 'integer']]);
        $category = Category::with('competition')->find($validated['category_id']);

        if (! $category || Gate::forUser($request->user())->denies('view', $category->competition)) {
            return Response::error('No existe esa categoría o no tienes acceso a ella.');
        }

        $history = Ranking::historyForCategory($category);

        $standings = $category->rankings()
            ->with(['pair.player1:id,name', 'pair.player2:id,name', 'player:id,name'])
            ->orderBy('position')
            ->get()
            ->map(fn (Ranking $ranking) => [
                'position' => $ranking->position,
                'name' => $ranking->pair
                    ? ($ranking->pair->name ?: "{$ranking->pair->player1?->name} / {$ranking->pair->player2?->name}")
                    : $ranking->player?->name,
                ...$ranking->only(['points', 'played', 'won', 'lost', 'sets_won', 'sets_lost', 'games_won', 'games_lost']),
                'form' => implode('', $history[$ranking->pair_id ?? $ranking->player_id]['form'] ?? []),
            ]);

        return Response::json([
            'competition' => $category->competition->name,
            'category' => $category->name,
            'calculated_at' => $category->rankings()->max('calculated_at'),
            'standings' => $standings->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category_id' => $schema->integer()->description('Id de la categoría (ver list_my_competitions).')->required(),
        ];
    }
}
