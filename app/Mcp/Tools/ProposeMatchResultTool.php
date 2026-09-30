<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsMatches;
use App\Models\PadelMatch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('propose_match_result')]
#[Description('Propone el resultado de un partido en el que juego. Los juegos van desde el punto de vista del lado 1 contra el lado 2 (ver side1/side2 en list_my_matches). Queda pendiente hasta que un rival lo confirme; si nadie responde en 48 horas, se confirma solo.')]
class ProposeMatchResultTool extends Tool
{
    use PresentsMatches;

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['match_id' => ['required', 'integer'], ...PadelMatch::PROPOSAL_RULES]);
        $user = $request->user();
        $match = PadelMatch::find($validated['match_id']);

        if (! $match || Gate::forUser($user)->denies('view', $match)) {
            return Response::error('No existe ese partido o no tienes acceso a él.');
        }

        $match->proposeResult($user, $validated['sets']);

        return Response::json($this->presentMatch($match->refresh(), $user));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'match_id' => $schema->integer()->description('Id del partido.')->required(),
            'sets' => $schema->array()->min(2)->max(3)->items($schema->object([
                'side1_games' => $schema->integer()->min(0)->required(),
                'side2_games' => $schema->integer()->min(0)->required(),
            ]))->description('Sets en orden, al mejor de tres. Válidos: 6 juegos con 2 de diferencia, 7-5 o 7-6.')->required(),
        ];
    }
}
