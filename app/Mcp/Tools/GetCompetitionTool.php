<?php

namespace App\Mcp\Tools;

use App\Models\Competition;
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
#[Name('get_competition')]
#[Description('Detalle de una competición: organizador, fechas, sede y sus categorías con el número de inscritos. Solo competiciones públicas o en las que participo.')]
class GetCompetitionTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['competition_id' => ['required', 'integer']]);
        $competition = Competition::find($validated['competition_id']);

        if (! $competition || Gate::forUser($request->user())->denies('view', $competition)) {
            return Response::error('No existe esa competición o no tienes acceso a ella.');
        }

        $competition->load('organizer:id,name');
        $categories = $competition->categories()
            ->withCount(['registrations' => fn ($query) => $query->where('status', 'confirmed')])
            ->get();

        return Response::json([
            'id' => $competition->id,
            'name' => $competition->name,
            'type' => $competition->type,
            'venue' => $competition->venue,
            'start_date' => $competition->start_date?->toDateString(),
            'end_date' => $competition->end_date?->toDateString(),
            'registration_closes_at' => $competition->registration_closes_at?->toIso8601String(),
            'is_private' => (bool) $competition->is_private,
            'cancelled' => $competition->cancelled_at !== null,
            'organizer' => $competition->organizer?->name,
            'categories' => $categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'registration_mode' => $category->registration_mode,
                'slots' => $category->slots,
                'confirmed_registrations' => $category->registrations_count,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'competition_id' => $schema->integer()->description('Id de la competición (ver list_my_competitions).')->required(),
        ];
    }
}
