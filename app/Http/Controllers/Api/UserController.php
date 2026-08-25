<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Busca jugadores por nombre o email para, p.ej., invitar a un
     * compañero a formar pareja. `search` es obligatorio (no se puede
     * listar el directorio completo de usuarios sin filtrar).
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        return User::query()
            ->where(fn ($query) => $query->where('name', 'like', "%{$validated['search']}%")
                ->orWhere('email', 'like', "%{$validated['search']}%")
            )
            ->orderBy('name')
            ->limit(20)
            ->get();
    }
}
