<?php

namespace App\Models;

use Database\Factories\PadelMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'phase_id',
    'side1_player1_id',
    'side1_player2_id',
    'side2_player1_id',
    'side2_player2_id',
    'scheduled_at',
    'court',
    'status',
    'winner_side',
])]
class PadelMatch extends Model
{
    /** @use HasFactory<PadelMatchFactory> */
    use HasFactory;

    protected $table = 'matches';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
        ];
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    public function side1Player1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'side1_player1_id');
    }

    public function side1Player2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'side1_player2_id');
    }

    public function side2Player1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'side2_player1_id');
    }

    public function side2Player2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'side2_player2_id');
    }

    public function matchSets(): HasMany
    {
        return $this->hasMany(MatchSet::class, 'match_id');
    }
}
