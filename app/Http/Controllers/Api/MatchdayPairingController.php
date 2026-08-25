<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatchdayPairing;
use App\Models\Phase;
use Illuminate\Http\Request;

class MatchdayPairingController extends Controller
{
    public function index(Phase $phase)
    {
        $this->authorize('view', $phase->category->competition);

        return $phase->matchdayPairings;
    }

    public function store(Request $request, Phase $phase)
    {
        $this->authorize('update', $phase->category->competition);

        $this->assertPhaseAcceptsPairings($phase);

        $validated = $request->validate([
            'player1_id' => ['required', 'integer', 'different:player2_id', 'exists:users,id'],
            'player2_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $this->assertPlayersAvailable($phase, $validated['player1_id'], $validated['player2_id']);

        $pairing = $phase->matchdayPairings()->create($validated);

        return response()->json($pairing, 201);
    }

    public function show(MatchdayPairing $matchday_pairing)
    {
        $this->authorize('view', $matchday_pairing->phase->category->competition);

        return $matchday_pairing;
    }

    public function update(Request $request, MatchdayPairing $matchday_pairing)
    {
        $this->authorize('update', $matchday_pairing->phase->category->competition);

        $validated = $request->validate([
            'player1_id' => ['sometimes', 'integer', 'different:player2_id', 'exists:users,id'],
            'player2_id' => ['sometimes', 'integer', 'exists:users,id'],
        ]);

        $this->assertPlayersAvailable(
            $matchday_pairing->phase,
            $validated['player1_id'] ?? $matchday_pairing->player1_id,
            $validated['player2_id'] ?? $matchday_pairing->player2_id,
            $matchday_pairing->id,
        );

        $matchday_pairing->update($validated);

        return $matchday_pairing;
    }

    public function destroy(MatchdayPairing $matchday_pairing)
    {
        $this->authorize('delete', $matchday_pairing->phase->category->competition);

        $matchday_pairing->delete();

        return response()->noContent();
    }

    /**
     * Los emparejamientos de jornada solo tienen sentido en fases
     * `matchday` de categorías con rotación individual (ver comentario
     * de la migración `create_matchday_pairings_table`).
     */
    private function assertPhaseAcceptsPairings(Phase $phase): void
    {
        abort_unless($phase->type === 'matchday', 422, 'Solo las fases de tipo "matchday" admiten emparejamientos.');

        abort_unless(
            $phase->category->registration_mode === 'individual_rotating',
            422,
            'Los emparejamientos de jornada solo aplican a categorías con rotación individual.'
        );
    }

    /**
     * Ningún jugador puede tener ya pareja asignada en esta jornada,
     * ni como player1 ni como player2 (las unique de la tabla solo cubren
     * cada columna por separado, no cruzado).
     */
    private function assertPlayersAvailable(Phase $phase, int $player1, int $player2, ?int $excludeId = null): void
    {
        $taken = MatchdayPairing::query()
            ->where('phase_id', $phase->id)
            ->when($excludeId, fn ($query) => $query->whereKeyNot($excludeId))
            ->where(fn ($query) => $query->whereIn('player1_id', [$player1, $player2])
                ->orWhereIn('player2_id', [$player1, $player2])
            )
            ->exists();

        abort_if($taken, 422, 'Uno de los jugadores ya tiene pareja asignada en esta jornada.');
    }
}
