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
            ->get();
    }

    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'partner_id' => ['required', 'integer', 'exists:users,id'],
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
        ]);

        return response()->json($pair, 201);
    }

    public function show(Pair $pair)
    {
        return $pair;
    }
}
