<?php

use App\Models\Competition;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.competition.{competition}', function (User $user, Competition $competition) {
    return $user->can('participate', $competition);
});
