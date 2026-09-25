<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatchMessage;
use App\Models\PadelMatch;
use Illuminate\Http\Request;

class MatchMessageController extends Controller
{
    /**
     * Mensajes del partido, los más recientes primero (30 por página); la app los
     * muestra en orden cronológico y carga páginas más antiguas bajo demanda.
     */
    public function index(PadelMatch $match)
    {
        $this->authorize('chat', $match);

        return $match->messages()
            ->with('author:id,name,level,club,city,avatar_color,avatar_emoji,avatar_path')
            ->latest('id')
            ->paginate(30)
            ->through(fn (MatchMessage $message) => $this->present($message));
    }

    public function store(Request $request, PadelMatch $match)
    {
        $this->authorize('chat', $match);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $message = $match->messages()->create([
            'author_id' => $request->user()->id,
            'body' => trim($validated['body']),
        ]);

        return response()->json($this->present($message->load('author')), 201);
    }

    /**
     * Cada uno borra el suyo; el organizador puede borrar cualquiera (moderación).
     */
    public function destroy(Request $request, MatchMessage $message)
    {
        $match = $message->match;
        abort_unless(
            $request->user()->id === $message->author_id
                || $request->user()->id === $match->phase->category->competition->organizer_id,
            403,
            'Solo puedes borrar tus mensajes.'
        );

        $message->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MatchMessage $message): array
    {
        return [
            'id' => $message->id,
            'body' => $message->body,
            'created_at' => $message->created_at,
            'author' => $message->author->publicSummary(),
        ];
    }
}
