<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

/**
 * Playoff opcional al final de una liga: lo crea y lo borra el organizador; el cuadro lo ve
 * quien pueda ver la competición. Los partidos se juegan y confirman como cualquier otro.
 */
class PlayoffController extends Controller
{
    public function store(Request $request, Category $category)
    {
        $this->authorize('update', $category->competition);
        abort_if($category->competition->cancelled_at !== null, 422, 'Esta competición ha sido cancelada.');

        $validated = $request->validate([
            'size' => ['required', 'integer', 'in:'.implode(',', Category::PLAYOFF_SIZES)],
        ], [
            'size.in' => 'El playoff puede ser de 4 u 8 parejas.',
        ]);

        $category->createPlayoff($validated['size']);

        return response()->json($category->playoffBracket(), 201);
    }

    public function show(Category $category)
    {
        $this->authorize('view', $category->competition);
        abort_unless($category->hasPlayoff(), 404, 'Esta categoría no tiene playoff.');

        return $category->playoffBracket();
    }

    public function destroy(Category $category)
    {
        $this->authorize('update', $category->competition);

        $category->deletePlayoff();

        return response()->noContent();
    }
}
