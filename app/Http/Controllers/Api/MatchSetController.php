<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatchSet;
use App\Models\PadelMatch;
use Illuminate\Http\Request;

class MatchSetController extends Controller
{
    public function index(PadelMatch $match)
    {
        $this->authorize('view', $match->phase->category->competition);

        return $match->matchSets()->orderBy('set_number')->get();
    }

    public function store(Request $request, PadelMatch $match)
    {
        $this->authorize('recordResult', $match);

        $validated = $request->validate([
            'set_number' => ['required', 'integer', 'between:1,3'],
            'side1_games' => ['required', 'integer', 'min:0'],
            'side2_games' => ['required', 'integer', 'min:0'],
        ]);

        abort_if(
            $match->matchSets()->where('set_number', $validated['set_number'])->exists(),
            422,
            'Ya existe un set con ese número para este partido.'
        );

        abort_unless(
            MatchSet::isValidScore($validated['side1_games'], $validated['side2_games']),
            422,
            'Resultado de set no válido: en pádel se gana con 6 juegos y 2 de diferencia, 7-5, o 7-6 en tie-break.'
        );

        abort_if(
            $match->isDecided(),
            422,
            'El partido ya está decidido (un lado ha ganado 2 sets); no se pueden añadir más sets.'
        );

        $set = $match->matchSets()->create($validated);

        return response()->json($set, 201);
    }

    public function show(MatchSet $set)
    {
        $this->authorize('view', $set->match->phase->category->competition);

        return $set;
    }

    public function update(Request $request, MatchSet $set)
    {
        $this->authorize('recordResult', $set->match);

        $validated = $request->validate([
            'side1_games' => ['sometimes', 'integer', 'min:0'],
            'side2_games' => ['sometimes', 'integer', 'min:0'],
        ]);

        abort_unless(
            MatchSet::isValidScore(
                $validated['side1_games'] ?? $set->side1_games,
                $validated['side2_games'] ?? $set->side2_games,
            ),
            422,
            'Resultado de set no válido: en pádel se gana con 6 juegos y 2 de diferencia, 7-5, o 7-6 en tie-break.'
        );

        $set->update($validated);

        return $set;
    }

    public function destroy(MatchSet $set)
    {
        $this->authorize('recordResult', $set->match);

        $set->delete();

        return response()->noContent();
    }
}
