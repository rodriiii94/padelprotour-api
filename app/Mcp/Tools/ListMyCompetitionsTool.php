<?php

namespace App\Mcp\Tools;

use App\Models\Competition;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('list_my_competitions')]
#[Description('Lista las competiciones (torneos y ligas) que organizo o en las que estoy inscrito, con sus categorías. Úsalo para obtener los ids que piden las demás herramientas.')]
class ListMyCompetitionsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = $request->user();

        $competitions = Competition::relatedTo($user)
            ->with('categories:id,competition_id,name,registration_mode')
            ->latest()
            ->get()
            ->map(fn (Competition $competition) => [
                'id' => $competition->id,
                'name' => $competition->name,
                'type' => $competition->type,
                'venue' => $competition->venue,
                'start_date' => $competition->start_date?->toDateString(),
                'end_date' => $competition->end_date?->toDateString(),
                'cancelled' => $competition->cancelled_at !== null,
                'i_am_organizer' => $competition->organizer_id === $user->id,
                'categories' => $competition->categories->map->only(['id', 'name'])->all(),
            ]);

        return Response::json($competitions->all());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
