<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PadelMatch;
use App\Models\Phase;
use App\Models\Ranking;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MatchController extends Controller
{
    /** Columnas que necesita User::publicSummary() para mostrar avatar junto al nombre. */
    private const PLAYER_COLUMNS = [...User::SEARCH_COLUMNS, 'avatar_path'];

    public function index(Phase $phase)
    {
        $this->authorize('view', $phase->category->competition);

        return $phase->matches()
            ->with($this->playerRelations())
            ->orderBy('scheduled_at')
            ->paginate(15)
            ->through(fn (PadelMatch $match) => $match->withPublicPlayers());
    }

    public function store(Request $request, Phase $phase)
    {
        $this->authorize('update', $phase->category->competition);

        $validated = $request->validate([
            'side1_player1_id' => ['required', 'integer', 'exists:users,id'],
            'side1_player2_id' => ['required', 'integer', 'exists:users,id', 'different:side1_player1_id'],
            'side2_player1_id' => ['required', 'integer', 'exists:users,id', 'different:side1_player1_id', 'different:side1_player2_id'],
            'side2_player2_id' => ['required', 'integer', 'exists:users,id', 'different:side1_player1_id', 'different:side1_player2_id', 'different:side2_player1_id'],
            'scheduled_at' => ['nullable', 'date'],
            'court' => ['nullable', 'string', 'max:255'],
        ]);

        $match = $phase->matches()->create([
            ...$validated,
            'status' => 'scheduled',
        ]);

        return response()->json($match, 201);
    }

    public function show(PadelMatch $match)
    {
        $this->authorize('view', $match->phase->category->competition);

        return $match->load(['matchSets', ...$this->playerRelations()])->withPublicPlayers();
    }

    public function update(Request $request, PadelMatch $match)
    {
        $this->authorize('update', $match->phase->category->competition);

        $validated = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
            'court' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:scheduled,in_progress,pending_validation,completed'],
            'winner_side' => ['nullable', 'integer', 'in:1,2'],
        ]);

        if ($request->hasAny(['status', 'winner_side'])) {
            $this->authorize('recordResult', $match);
        }

        // El ganador sale de los sets anotados, no de lo que diga el cliente: un partido
        // sin decidir (p. ej. un solo set) no se puede dar por terminado.
        if (($validated['status'] ?? null) === 'completed') {
            $match->load('matchSets');
            throw_unless($match->isDecided(), ValidationException::withMessages([
                'status' => ['El partido no está decidido: un lado debe ganar 2 sets antes de finalizarlo.'],
            ]));

            $winnerSide = $match->setsWon(1) >= 2 ? 1 : 2;
            throw_if(isset($validated['winner_side']) && $validated['winner_side'] !== $winnerSide, ValidationException::withMessages([
                'winner_side' => ['El ganador no coincide con los sets anotados.'],
            ]));
            $validated['winner_side'] = $winnerSide;
        }

        $match->update($validated);

        // Al completar un partido, la clasificación de su categoría queda
        // desactualizada al instante -- se recalcula sola, sin que el
        // organizador tenga que llamar aparte a /rankings/recalculate.
        if ($match->status === 'completed') {
            Ranking::recalculateForCategory($match->phase->category);
        }

        return $match;
    }

    public function destroy(PadelMatch $match)
    {
        $this->authorize('delete', $match->phase->category->competition);

        $match->delete();

        return response()->noContent();
    }

    /**
     * @return list<string>
     */
    private function playerRelations(): array
    {
        $columns = implode(',', self::PLAYER_COLUMNS);

        return array_map(
            fn (string $relation) => "{$relation}:{$columns}",
            ['side1Player1', 'side1Player2', 'side2Player1', 'side2Player2'],
        );
    }
}
