<?php

namespace App\Policies;

use App\Models\Registration;
use App\Models\User;

class RegistrationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Registration $registration): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     *
     * Solo el organizador de la competición cambia el estado de una
     * inscripción (confirmar, poner en lista de espera, rechazar).
     */
    public function update(User $user, Registration $registration): bool
    {
        return $user->id === $registration->category->competition->organizer_id;
    }

    /**
     * Determine whether the user can delete the model.
     *
     * El organizador puede retirar cualquier inscripción; el propio
     * inscrito solo puede retirarse mientras siga "pending".
     */
    public function delete(User $user, Registration $registration): bool
    {
        if ($user->id === $registration->category->competition->organizer_id) {
            return true;
        }

        return $registration->status === 'pending' && $this->isRegistrant($user, $registration);
    }

    private function isRegistrant(User $user, Registration $registration): bool
    {
        if ($registration->player_id !== null) {
            return $registration->player_id === $user->id;
        }

        if ($registration->pair === null) {
            return false;
        }

        return in_array($user->id, [$registration->pair->player1_id, $registration->pair->player2_id], true);
    }
}
