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

        $validated = $request->validate(['search' => ['nullable', 'string', 'min:2', 'max:255']]);

        $competitions = Competition::where('is_private', false)
            ->whereNull('cancelled_at')
            ->when($request->boolean('upcoming'), fn ($query) => $query->upcoming())
            ->when($validated['search'] ?? null, fn ($query, string $search) => $query->where(
                fn ($query) => $query->whereLike('name', "%{$search}%")->orWhereLike('venue', "%{$search}%")
            ))
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
            ...$this->dateRules($request),
            'is_private' => ['sometimes', 'boolean'],
            'double_round' => ['sometimes', 'boolean'],
        ]);

        // Ida y vuelta solo tiene sentido en una liga; en un torneo se ignora. Se fija aquí
        // (no solo con el default de la columna) para que ya salga en la respuesta.
        $validated['double_round'] = $validated['type'] === 'league' && ($validated['double_round'] ?? false);

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
            ...$this->dateRules($request),
            'is_private' => ['sometimes', 'boolean'],
            'double_round' => ['sometimes', 'boolean'],
        ]);

        // Ida y vuelta solo tiene sentido en una liga; en un torneo se ignora.
        if (($validated['type'] ?? $competition->type) !== 'league') {
            $validated['double_round'] = false;
        }

        $competition->update($validated);

        return $competition->revealInviteTokenFor($request->user());
    }

    public function destroy(Competition $competition)
    {
        $this->authorize('delete', $competition);

        $competition->delete();

        return response()->noContent();
    }

    public function cancel(Request $request, Competition $competition)
    {
        $this->authorize('update', $competition);

        abort_if($competition->cancelled_at !== null, 422, 'Esta competición ya está cancelada.');

        $competition->forceFill(['cancelled_at' => now()])->save();

        return $competition->revealInviteTokenFor($request->user());
    }

    /**
     * Invalida el enlace de invitación actual y crea uno nuevo, por si se ha filtrado.
     */
    public function regenerateInvite(Request $request, Competition $competition)
    {
        $this->authorize('update', $competition);

        $competition->forceFill(['invite_token' => Str::random(32)])->save();

        return $competition->revealInviteTokenFor($request->user());
    }

    /**
     * Fechas de la competición. La de inicio es opcional; el orden entre fechas solo se
     * comprueba contra ella si se ha enviado.
     *
     * @return array<string, list<string>>
     */
    private function dateRules(Request $request): array
    {
        $hasStart = $request->filled('start_date');

        return [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', ...($hasStart ? ['after_or_equal:start_date'] : [])],
            'registration_closes_at' => ['nullable', 'date', ...($hasStart ? ['before_or_equal:start_date'] : [])],
        ];
    }
}
