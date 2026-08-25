<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use Illuminate\Http\Request;

class CompetitionController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Competition::class);

        return Competition::latest()->paginate(15);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Competition::class);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:tournament,league'],
            'name' => ['required', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'registration_closes_at' => ['nullable', 'date', 'before_or_equal:start_date'],
        ]);

        $competition = Competition::create([
            ...$validated,
            'organizer_id' => $request->user()->id,
        ]);

        return response()->json($competition, 201);
    }

    public function show(Competition $competition)
    {
        $this->authorize('view', $competition);

        return $competition;
    }

    public function update(Request $request, Competition $competition)
    {
        $this->authorize('update', $competition);

        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:tournament,league'],
            'name' => ['sometimes', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'registration_closes_at' => ['nullable', 'date', 'before_or_equal:start_date'],
        ]);

        $competition->update($validated);

        return $competition;
    }

    public function destroy(Competition $competition)
    {
        $this->authorize('delete', $competition);

        $competition->delete();

        return response()->noContent();
    }
}
