<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PadelMatch;
use Illuminate\Http\Request;

/**
 * Reserva de pista del partido: club, día/hora, pista y el enlace al partido de Playtomic.
 * Playtomic no ofrece una API pública, así que los datos los apunta el jugador a mano y el
 * enlace solo sirve para abrir el partido en Playtomic.
 */
class MatchBookingController extends Controller
{
    public function update(Request $request, PadelMatch $match)
    {
        $this->authorize('book', $match);

        $match->updateBooking($request->validate(PadelMatch::BOOKING_RULES, PadelMatch::BOOKING_MESSAGES));

        return $match->only(['id', 'scheduled_at', 'club', 'court', 'playtomic_url']);
    }
}
