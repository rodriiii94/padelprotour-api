<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Pair;
use App\Models\Registration;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RegistrationController extends Controller
{
    public function index(Category $category)
    {
        $this->authorize('viewAny', Registration::class);

        return $category->registrations()
            ->with(['player:id,name', 'pair.player1:id,name', 'pair.player2:id,name'])
            ->latest()
            ->paginate(15);
    }

    public function store(Request $request, Category $category)
    {
        $this->authorize('create', Registration::class);

        // Pareja fija para torneos y categorías de liga "fixed_pair" (o sin
        // modo definido); jugador individual solo para "individual_rotating".
        $usesPlayer = $category->registration_mode === 'individual_rotating';

        $validated = $request->validate([
            'pair_id' => [$usesPlayer ? 'prohibited' : 'required', 'integer', Rule::exists('pairs', 'id')],
            'player_id' => [$usesPlayer ? 'required' : 'prohibited', 'integer', Rule::exists('users', 'id')],
        ]);

        if ($usesPlayer) {
            abort_if($validated['player_id'] !== $request->user()->id, 403, 'Solo puedes inscribirte a ti mismo.');
        } else {
            $pair = Pair::findOrFail($validated['pair_id']);
            abort_unless(
                in_array($request->user()->id, [$pair->player1_id, $pair->player2_id], true),
                403,
                'Solo puedes inscribir una pareja de la que formes parte.'
            );
        }

        abort_if(
            $category->competition->cancelled_at !== null,
            422,
            'Esta competición ha sido cancelada.'
        );

        abort_if(
            $category->competition->registration_closes_at?->isPast(),
            422,
            'El plazo de inscripción para esta competición ya ha cerrado.'
        );

        $alreadyRegistered = $category->registrations()
            ->where($usesPlayer ? 'player_id' : 'pair_id', $usesPlayer ? $validated['player_id'] : $validated['pair_id'])
            ->where('status', '!=', 'rejected')
            ->exists();

        abort_if($alreadyRegistered, 422, 'Ya existe una inscripción para esta categoría.');

        $registration = $category->registrations()->create([
            ...$validated,
            'status' => 'pending',
        ]);

        return response()->json($registration, 201);
    }

    public function show(Registration $registration)
    {
        $this->authorize('view', $registration);

        return $registration->load(['player:id,name', 'pair.player1:id,name', 'pair.player2:id,name']);
    }

    public function update(Request $request, Registration $registration)
    {
        $this->authorize('update', $registration);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,confirmed,waitlisted,rejected'],
        ]);

        $registration->update($validated);

        return $registration;
    }

    public function destroy(Registration $registration)
    {
        $this->authorize('delete', $registration);

        $registration->delete();

        return response()->noContent();
    }
}
