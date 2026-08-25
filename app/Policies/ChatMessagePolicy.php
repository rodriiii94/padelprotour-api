<?php

namespace App\Policies;

use App\Models\ChatMessage;
use App\Models\User;

class ChatMessagePolicy
{
    /**
     * El organizador puede borrar cualquier mensaje; el autor puede
     * borrar el suyo propio.
     */
    public function delete(User $user, ChatMessage $chatMessage): bool
    {
        return $user->id === $chatMessage->author_id
            || $user->id === $chatMessage->competition->organizer_id;
    }
}
