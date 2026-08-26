<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use Illuminate\Http\Request;

class InviteController extends Controller
{
    /**
     * Resuelve un enlace de invitación por su token, sin pasar por la
     * policy normal de `view` (que rechazaría una competición privada a
     * quien todavía no participa en ella). Conocer el token es en sí
     * mismo la autorización.
     */
    public function show(Request $request, string $token)
    {
        $competition = Competition::where('invite_token', $token)->firstOrFail();

        return $competition->revealInviteTokenFor($request->user());
    }
}
