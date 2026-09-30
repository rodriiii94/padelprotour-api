<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PadelMatch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Reserva de pista del partido: club, día/hora, pista y el enlace al partido de Playtomic.
 * Playtomic no ofrece una API pública, así que los datos los apunta el jugador a mano y el
 * enlace solo sirve para abrir el partido en Playtomic.
 */
class MatchBookingController extends Controller
{
    /** Solo enlaces https de Playtomic (p. ej. los de compartir, `app.playtomic.com/t/...`). */
    private const PLAYTOMIC_URL_PATTERN = '/^https:\/\/([a-z0-9-]+\.)*playtomic\.(com|io)(\/\S*)?$/i';

    public function update(Request $request, PadelMatch $match)
    {
        $this->authorize('book', $match);
        abort_if($match->phase->category->competition->cancelled_at !== null, 422, 'Esta competición ha sido cancelada.');

        $validated = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
            'club' => ['nullable', 'string', 'max:120'],
            'court' => ['nullable', 'string', 'max:255'],
            'playtomic_url' => ['nullable', 'string', 'max:255', 'url:https', 'regex:'.self::PLAYTOMIC_URL_PATTERN],
        ], [
            'playtomic_url.url' => 'El enlace debe ser un enlace de Playtomic.',
            'playtomic_url.regex' => 'El enlace debe ser un enlace de Playtomic.',
        ]);

        if (isset($validated['scheduled_at'])) {
            $validated['scheduled_at'] = Carbon::parse($validated['scheduled_at'])->utc();
        }

        $match->update($validated);

        return $match->only(['id', 'scheduled_at', 'club', 'court', 'playtomic_url']);
    }
}
