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
        $this->authorize('update', $match->phase->category->competition);

        $validated = $request->validate([
            'set_number' => ['required', 'integer', 'min:1'],
            'side1_games' => ['required', 'integer', 'min:0'],
            'side2_games' => ['required', 'integer', 'min:0'],
        ]);

        abort_if(
            $match->matchSets()->where('set_number', $validated['set_number'])->exists(),
            422,
            'Ya existe un set con ese número para este partido.'
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
        $this->authorize('update', $set->match->phase->category->competition);

        $validated = $request->validate([
            'side1_games' => ['sometimes', 'integer', 'min:0'],
            'side2_games' => ['sometimes', 'integer', 'min:0'],
        ]);

        $set->update($validated);

        return $set;
    }

    public function destroy(MatchSet $set)
    {
        $this->authorize('delete', $set->match->phase->category->competition);

        $set->delete();

        return response()->noContent();
    }
}
