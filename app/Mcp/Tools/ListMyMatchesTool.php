<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsMatches;
use App\Models\PadelMatch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('list_my_matches')]
#[Description('Mis partidos con rivales, sets y reserva de pista (día/hora, club, pista, enlace de Playtomic). Filtra por próximos, pendientes de validar o jugados.')]
class ListMyMatchesTool extends Tool
{
    use PresentsMatches;

    private const STATUSES = [
        'upcoming' => ['scheduled', 'in_progress'],
        'pending_validation' => ['pending_validation'],
        'completed' => ['completed'],
    ];

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'filter' => ['nullable', 'string', 'in:upcoming,pending_validation,completed'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $filter = $validated['filter'] ?? 'upcoming';
        $user = $request->user();

        $matches = PadelMatch::query()
            ->forPlayer($user)
            ->whereIn('status', self::STATUSES[$filter])
            ->with($this->matchRelations)
            ->when(
                $filter === 'completed',
                fn ($query) => $query->latest('updated_at'),
                fn ($query) => $query->orderByRaw('scheduled_at IS NULL')->orderBy('scheduled_at'),
            )
            ->limit($validated['limit'] ?? 20)
            ->get();

        return Response::json($matches->map(fn (PadelMatch $match) => $this->presentMatch($match, $user))->all());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()->enum(['upcoming', 'pending_validation', 'completed'])
                ->description('upcoming (por defecto): sin resultado, por fecha. pending_validation: resultado propuesto sin confirmar. completed: jugados, los más recientes primero.'),
            'limit' => $schema->integer()->min(1)->max(50)->description('Máximo de partidos (por defecto 20).'),
        ];
    }
}
