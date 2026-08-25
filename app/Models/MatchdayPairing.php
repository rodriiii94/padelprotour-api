<?php

namespace App\Models;

use Database\Factories\MatchdayPairingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['phase_id', 'player1_id', 'player2_id'])]
class MatchdayPairing extends Model
{
    /** @use HasFactory<MatchdayPairingFactory> */
    use HasFactory;

    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    public function player1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player1_id');
    }

    public function player2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'player2_id');
    }
}
