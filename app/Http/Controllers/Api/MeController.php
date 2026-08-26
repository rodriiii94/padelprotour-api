<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
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
}
