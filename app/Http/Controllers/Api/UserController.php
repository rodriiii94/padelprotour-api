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
            ->get([...User::SEARCH_COLUMNS, 'avatar_path'])
            ->map(fn (User $user) => $user->publicSummary());
    }

    /**
     * Ficha pública de un jugador: todos los perfiles son visibles para
     * cualquier usuario autenticado, salvo el email y los datos de login.
     */
    public function show(Request $request, User $user)
    {
        return [
            ...$user->only(User::PUBLIC_COLUMNS),
            'avatar_url' => $user->avatar_url,
            ...$user->playerSummary(),
            'followers_count' => $user->followers()->count(),
            'following_count' => $user->following()->count(),
            'is_following' => $request->user()->following()->whereKey($user->id)->exists(),
        ];
    }

    /**
     * Empieza a seguir a un jugador. Idempotente: seguir a quien ya sigues no falla.
     * No hay aprobación porque todos los perfiles son públicos.
     */
    public function follow(Request $request, User $user)
    {
        abort_if($request->user()->is($user), 422, 'No puedes seguirte a ti mismo.');

        $request->user()->following()->syncWithoutDetaching([$user->id]);

        return response()->noContent();
    }

    public function unfollow(Request $request, User $user)
    {
        $request->user()->following()->detach($user->id);

        return response()->noContent();
    }

    public function followers(User $user)
    {
        return $user->followers()->orderBy('name')->paginate(20)
            ->through(fn (User $follower) => $follower->publicSummary());
    }

    public function following(User $user)
    {
        return $user->following()->orderBy('name')->paginate(20)
            ->through(fn (User $followed) => $followed->publicSummary());
    }
}
