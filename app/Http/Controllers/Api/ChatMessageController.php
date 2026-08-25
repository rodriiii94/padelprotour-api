<?php

namespace App\Http\Controllers\Api;

use App\Events\ChatMessageSent;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Competition;
use Illuminate\Http\Request;

class ChatMessageController extends Controller
{
    public function index(Competition $competition)
    {
        $this->authorize('participate', $competition);

        return $competition->chatMessages()->with('author:id,name')->latest()->paginate(30);
    }

    public function store(Request $request, Competition $competition)
    {
        $this->authorize('participate', $competition);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $chatMessage = $competition->chatMessages()->create([
            ...$validated,
            'author_id' => $request->user()->id,
        ]);

        event(new ChatMessageSent($chatMessage));

        return response()->json($chatMessage->load('author:id,name'), 201);
    }

    public function destroy(ChatMessage $chat_message)
    {
        $this->authorize('delete', $chat_message);

        $chat_message->delete();

        return response()->noContent();
    }
}
