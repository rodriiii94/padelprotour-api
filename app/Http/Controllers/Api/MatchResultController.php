<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatchSet;
use App\Models\PadelMatch;
use App\Models\Ranking;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $competition = $match->phase->category->competition;
        $user = $request->user();

        abort_unless($match->sideOf($user) !== null, 403, 'Solo los jugadores del partido pueden proponer el resultado.');
        abort_if($competition->cancelled_at !== null, 422, 'Esta competición ha sido cancelada.');
        abort_if(
            in_array($match->status, ['completed', 'pending_validation'], true),
            422,
            $match->status === 'completed'
                ? 'El partido ya tiene un resultado validado.'
                : 'Ya hay un resultado pendiente de validar.'
        );

        $validated = $request->validate([
            'sets' => ['required', 'array', 'min:2', 'max:3'],
            'sets.*.side1_games' => ['required', 'integer', 'min:0'],
            'sets.*.side2_games' => ['required', 'integer', 'min:0'],
        ]);

        $winnerSide = $this->winnerOf($validated['sets']);

        DB::transaction(function () use ($match, $validated, $winnerSide, $user): void {
            $match->matchSets()->delete();

            foreach (array_values($validated['sets']) as $index => $set) {
                $match->matchSets()->create([
                    'set_number' => $index + 1,
                    'side1_games' => $set['side1_games'],
                    'side2_games' => $set['side2_games'],
                ]);
            }

            $match->forceFill([
                'status' => 'pending_validation',
                'winner_side' => $winnerSide,
                'result_proposed_by' => $user->id,
                'result_proposed_at' => now(),
            ])->save();
        });

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
     * Ganador (1 o 2) de los sets propuestos. El partido es al mejor de tres: debe estar
     * decidido exactamente al final y no puede seguir después de decidirse.
     *
     * @param  array<int, array{side1_games: int, side2_games: int}>  $sets
     */
    private function winnerOf(array $sets): int
    {
        $won = [1 => 0, 2 => 0];

        foreach (array_values($sets) as $index => $set) {
            if (! MatchSet::isValidScore($set['side1_games'], $set['side2_games'])) {
                throw ValidationException::withMessages([
                    'sets' => ['Resultado de set no válido: en pádel se gana con 6 juegos y 2 de diferencia, 7-5, o 7-6 en tie-break.'],
                ]);
            }

            if ($won[1] >= 2 || $won[2] >= 2) {
                throw ValidationException::withMessages(['sets' => ['El partido ya estaba decidido antes del último set.']]);
            }

            $won[$set['side1_games'] > $set['side2_games'] ? 1 : 2]++;
        }

        if ($won[1] < 2 && $won[2] < 2) {
            throw ValidationException::withMessages(['sets' => ['El resultado no decide el partido: un lado debe ganar 2 sets.']]);
        }

        return $won[1] >= 2 ? 1 : 2;
    }

    private function present(PadelMatch $match): PadelMatch
    {
        return $match->refresh()->load([
            'matchSets',
            'side1Player1:id,name',
            'side1Player2:id,name',
            'side2Player1:id,name',
            'side2Player2:id,name',
        ]);
    }
}
