<?php

namespace App\Policies;

use App\Models\Competition;
use App\Models\User;

class CompetitionPolicy
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
    public function view(User $user, Competition $competition): bool
    {
        return ! $competition->is_private
            || $competition->hasParticipant($user)
            || $this->presentsInviteToken($competition);
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
     */
    public function update(User $user, Competition $competition): bool
    {
        return $user->id === $competition->organizer_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Competition $competition): bool
    {
        return $user->id === $competition->organizer_id;
    }

    /**
     * Determine whether the user can read/post in the competition chat.
     */
    public function participate(User $user, Competition $competition): bool
    {
        return $competition->hasParticipant($user);
    }

    /**
     * Quien todavía no participa entra a una competición privada con el código de su
     * enlace de invitación (`?invite=`): conocerlo es en sí mismo la autorización, igual
     * que en InviteController. Solo abre la lectura; inscribirse no depende de esto.
     */
    private function presentsInviteToken(Competition $competition): bool
    {
        $token = request()->query('invite');

        return is_string($token)
            && $competition->invite_token !== null
            && hash_equals($competition->invite_token, $token);
    }
}
