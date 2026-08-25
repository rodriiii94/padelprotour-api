<?php

namespace App\Models;

use Database\Factories\MatchSetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['match_id', 'set_number', 'side1_games', 'side2_games'])]
class MatchSet extends Model
{
    /** @use HasFactory<MatchSetFactory> */
    use HasFactory;

    public function match(): BelongsTo
    {
        return $this->belongsTo(PadelMatch::class, 'match_id');
    }
}
