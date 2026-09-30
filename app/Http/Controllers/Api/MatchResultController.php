<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PadelMatch;
use App\Models\Ranking;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Resultados propuestos por los jugadores: uno de los cuatro propone los sets, un rival
 * (o el organizador) lo confirma y solo entonces el partido cuenta como `completed`.
 * Mientras tanto queda en `pending_validation`, que ni la clasificación ni las
 * estadísticas cuentan.
 */
class MatchResultController extends Controller
{
    public function propose(Request $request, PadelMatch $match)
    {
        $validated = $request->validate(PadelMatch::PROPOSAL_RULES);

        $match->proposeResult($request->user(), $validated['sets']);

        return response()->json($this->present($match), 201);
    }

    public function confirm(Request $request, PadelMatch $match)
    {
        $this->authorizeReview($request->user(), $match, allowProposer: false);

        $match->forceFill(['status' => 'completed'])->save();

        Ranking::recalculateForCategory($match->phase->category);

        return $this->present($match);
    }

    public function reject(Request $request, PadelMatch $match)
    {
        $this->authorizeReview($request->user(), $match, allowProposer: true);

        DB::transaction(function () use ($match): void {
            $match->matchSets()->delete();
            $match->forceFill([
                'status' => 'scheduled',
                'winner_side' => null,
                'result_proposed_by' => null,
                'result_proposed_at' => null,
            ])->save();
        });

        return $this->present($match);
    }

    /**
     * Confirma o rechaza quien juega en el lado contrario al que propuso, o el organizador.
     * Rechazar además lo puede hacer el propio proponente (retirar su propuesta).
     */
    private function authorizeReview(User $user, PadelMatch $match, bool $allowProposer): void
    {
        abort_unless($match->status === 'pending_validation', 422, 'No hay ningún resultado pendiente de validar.');

        $isOrganizer = $user->id === $match->phase->category->competition->organizer_id;
        $proposer = User::find($match->result_proposed_by);
        $proposerSide = $proposer ? $match->sideOf($proposer) : null;
        $userSide = $match->sideOf($user);

        $isOpponent = $userSide !== null && $userSide !== $proposerSide;
        $isProposer = $user->id === $match->result_proposed_by;

        abort_unless(
            $isOrganizer || $isOpponent || ($allowProposer && $isProposer),
            403,
            'Solo un rival del que propuso el resultado, o el organizador, puede validarlo.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PadelMatch $match): array
    {
        $columns = implode(',', [...User::SEARCH_COLUMNS, 'avatar_path']);

        return $match->refresh()->load([
            'matchSets',
            "side1Player1:{$columns}",
            "side1Player2:{$columns}",
            "side2Player1:{$columns}",
            "side2Player2:{$columns}",
        ])->withPublicPlayers();
    }
}
