<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Busca jugadores por nombre para, p.ej., invitar a un compañero a
     * formar pareja. `search` es obligatorio (no se puede listar el
     * directorio completo de usuarios sin filtrar). Solo se busca y se
     * devuelve información pública: nunca el email.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        return User::query()
            ->whereLike('name', "%{$validated['search']}%")
            ->orderBy('name')
            ->limit(20)
            ->get(User::SEARCH_COLUMNS);
    }

    /**
     * Ficha pública de un jugador: todos los perfiles son visibles para
     * cualquier usuario autenticado, salvo el email y los datos de login.
     */
    public function show(User $user)
    {
        return [
            ...$user->only(User::PUBLIC_COLUMNS),
            ...$user->playerSummary(),
        ];
    }
}
