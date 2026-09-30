<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\PadelMatch;
use App\Models\Registration;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function registrations(Request $request)
    {
        return Registration::forUser($request->user())
            ->with(['category.competition', 'pair'])
            ->latest()
            ->get();
    }

    public function competitions(Request $request)
    {
        return Competition::relatedTo($request->user())
            ->latest()
            ->get()
            ->each->revealInviteTokenFor($request->user());
    }

    /**
     * Clubes donde ya ha jugado el usuario, los más recientes primero -- sugerencias para
     * apuntar la reserva de un partido.
     *
     * @return list<string>
     */
    public function clubs(Request $request): array
    {
        return PadelMatch::query()
            ->forPlayer($request->user())
            ->whereNotNull('club')
            ->groupBy('club')
            ->orderByRaw('MAX(updated_at) DESC')
            ->limit(10)
            ->pluck('club')
            ->all();
    }
}
