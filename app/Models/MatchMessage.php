<?php

namespace App\Models;

use Database\Factories\MatchMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mensaje del chat de un partido: solo lo ven y escriben sus cuatro jugadores y el
 * organizador, para concretar día, hora y pista.
 */
#[Fillable(['match_id', 'author_id', 'body'])]
class MatchMessage extends Model
{
    /** @use HasFactory<MatchMessageFactory> */
    use HasFactory;

    public function match(): BelongsTo
    {
        return $this->belongsTo(PadelMatch::class, 'match_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
