<?php

namespace App\Policies;

use App\Models\PadelMatch;
use App\Models\User;

class PadelMatchPolicy
{
    /**
     * Leer y escribir en el chat del partido: sus cuatro jugadores y el organizador
     * de la competición.
     */
    public function chat(User $user, PadelMatch $match): bool
    {
        return $match->sideOf($user) !== null
            || $user->id === $match->phase->category->competition->organizer_id;
    }
}
