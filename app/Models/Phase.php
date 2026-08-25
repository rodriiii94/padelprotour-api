<?php

namespace App\Models;

use Database\Factories\PhaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['category_id', 'type', 'name', 'order'])]
class Phase extends Model
{
    /** @use HasFactory<PhaseFactory> */
    use HasFactory;

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function matchdayPairings(): HasMany
    {
        return $this->hasMany(MatchdayPairing::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(PadelMatch::class);
    }
}
