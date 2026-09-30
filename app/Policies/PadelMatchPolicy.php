<?php

namespace App\Policies;

use App\Models\PadelMatch;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PadelMatchPolicy
{
    /**
     * Ver el partido: sus cuatro jugadores o quien pueda ver la competición.
     */
    public function view(User $user, PadelMatch $match): bool
    {
        return $match->sideOf($user) !== null
            || $user->can('view', $match->phase->category->competition);
    }

    /**
     * Leer y escribir en el chat del partido: sus cuatro jugadores y el organizador
     * de la competición.
     */
    public function chat(User $user, PadelMatch $match): bool
    {
        return $match->sideOf($user) !== null
            || $user->id === $match->phase->category->competition->organizer_id;
    }

    /**
     * Apuntar la reserva de pista (club, día/hora, enlace de Playtomic): quien reserva suele
     * ser uno de los jugadores, así que pueden los mismos que en el chat.
     */
    public function book(User $user, PadelMatch $match): bool
    {
        return $this->chat($user, $match);
    }

    /**
     * Anotar sets y finalizar el partido sin confirmación de nadie: solo el organizador, y
     * solo si no juega este partido. Si juega, es parte interesada y va por el flujo de
     * propuesta que confirma un rival, como cualquier otro jugador.
     */
    public function recordResult(User $user, PadelMatch $match): Response
    {
        if ($user->id !== $match->phase->category->competition->organizer_id) {
            return Response::deny('Solo el organizador puede anotar el resultado directamente.');
        }

        return $match->sideOf($user) === null
            ? Response::allow()
            : Response::deny('Juegas este partido: propón el resultado y que lo confirme un rival.');
    }
}
