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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Name('update_match_booking')]
#[Description('Apunta la reserva de pista de un partido en el que juego u organizo: día y hora, club, pista y enlace de Playtomic. Solo cambia los campos que envíes; envía null para borrar uno.')]
class UpdateMatchBookingTool extends Tool
{
    use PresentsMatches;

    public function handle(Request $request): Response
    {
        $validated = $request->validate(
            ['match_id' => ['required', 'integer'], ...PadelMatch::BOOKING_RULES],
            PadelMatch::BOOKING_MESSAGES,
        );
        $user = $request->user();
        $match = PadelMatch::find($validated['match_id']);

        if (! $match || Gate::forUser($user)->denies('view', $match)) {
            return Response::error('No existe ese partido o no tienes acceso a él.');
        }

        Gate::forUser($user)->authorize('book', $match);

        $match->updateBooking(collect($validated)->except('match_id')->all());

        return Response::json($this->presentMatch($match->refresh(), $user));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'match_id' => $schema->integer()->description('Id del partido.')->required(),
            'scheduled_at' => $schema->string()->format('date-time')->nullable()
                ->description('Fecha y hora ISO 8601 con zona horaria, p. ej. 2026-10-04T19:30:00+02:00.'),
            'club' => $schema->string()->nullable()->description('Nombre del club.'),
            'court' => $schema->string()->nullable()->description('Pista, p. ej. "Pista 3".'),
            'playtomic_url' => $schema->string()->nullable()->description('Enlace https de Playtomic del partido.'),
        ];
    }
}
