<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pair;
use Illuminate\Http\Request;

class PairController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return Pair::query()
            ->where('player1_id', $user->id)
            ->orWhere('player2_id', $user->id)
            ->with(['player1:id,name', 'player2:id,name'])
            ->get();
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'partner_id' => ['required', 'integer', 'exists:users,id'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        abort_if($validated['partner_id'] === $user->id, 422, 'No puedes formar pareja contigo mismo.');

        $exists = Pair::query()
            ->where(fn ($query) => $query->where('player1_id', $user->id)->where('player2_id', $validated['partner_id']))
            ->orWhere(fn ($query) => $query->where('player1_id', $validated['partner_id'])->where('player2_id', $user->id))
            ->exists();

        abort_if($exists, 422, 'Ya existe una pareja con ese jugador.');

        $pair = Pair::create([
            'player1_id' => $user->id,
            'player2_id' => $validated['partner_id'],
            'name' => $validated['name'] ?? null,
        ]);

        return response()->json($pair->load(['player1:id,name', 'player2:id,name']), 201);
    }

    /**
     * Solo sus dos integrantes: nadie más necesita mirar una pareja suelta (en categorías e
     * inscripciones ya viene embebida). Antes no comprobaba nada, así que se podía consultar
     * cualquier pareja adivinando su id.
     */
    public function show(Request $request, Pair $pair)
    {
        abort_unless(
            in_array($request->user()->id, [$pair->player1_id, $pair->player2_id], true),
            403,
            'Solo puedes ver una pareja de la que formes parte.'
        );

        return $pair->load(['player1:id,name', 'player2:id,name']);
    }
}
