<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PadelMatch;
use App\Models\Phase;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function index(Phase $phase)
    {
        $this->authorize('view', $phase->category->competition);

        return $phase->matches()->orderBy('scheduled_at')->paginate(15);
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

        return $match->load('matchSets');
    }

    public function update(Request $request, PadelMatch $match)
    {
        $this->authorize('update', $match->phase->category->competition);

        $validated = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
            'court' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', 'in:scheduled,in_progress,pending_validation,completed'],
            'winner_side' => ['nullable', 'integer', 'in:1,2', 'required_if:status,completed'],
        ]);

        $match->update($validated);

        return $match;
    }

    public function destroy(PadelMatch $match)
    {
        $this->authorize('delete', $match->phase->category->competition);

        $match->delete();

        return response()->noContent();
    }
}
