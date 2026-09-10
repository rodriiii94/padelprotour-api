<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CompetitionController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Competition::class);

        $competitions = Competition::where('is_private', false)
            ->when($request->boolean('upcoming'), fn ($query) => $query->upcoming())
            ->latest()
            ->paginate(15);
        $competitions->getCollection()->each->revealInviteTokenFor($request->user());

        return $competitions;
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
            'is_private' => ['sometimes', 'boolean'],
        ]);

        $competition = Competition::create([
            ...$validated,
            'organizer_id' => $request->user()->id,
            'invite_token' => Str::random(32),
        ]);

        return response()->json($competition->revealInviteTokenFor($request->user()), 201);
    }

    public function show(Request $request, Competition $competition)
    {
        $this->authorize('view', $competition);

        return $competition->revealInviteTokenFor($request->user());
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
            'is_private' => ['sometimes', 'boolean'],
        ]);

        $competition->update($validated);

        return $competition->revealInviteTokenFor($request->user());
    }

    public function destroy(Competition $competition)
    {
        $this->authorize('delete', $competition);

        $competition->delete();

        return response()->noContent();
    }
}
