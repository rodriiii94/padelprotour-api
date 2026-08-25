<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Phase;
use Illuminate\Http\Request;

class PhaseController extends Controller
{
    public function index(Category $category)
    {
        $this->authorize('view', $category->competition);

        return $category->phases()->orderBy('order')->get();
    }

    public function store(Request $request, Category $category)
    {
        $this->authorize('update', $category->competition);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:group,elimination_round,matchday'],
            'name' => ['required', 'string', 'max:255'],
            'order' => ['required', 'integer', 'min:1'],
        ]);

        $phase = $category->phases()->create($validated);

        return response()->json($phase, 201);
    }

    public function show(Phase $phase)
    {
        $this->authorize('view', $phase->category->competition);

        return $phase;
    }

    public function update(Request $request, Phase $phase)
    {
        $this->authorize('update', $phase->category->competition);

        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:group,elimination_round,matchday'],
            'name' => ['sometimes', 'string', 'max:255'],
            'order' => ['sometimes', 'integer', 'min:1'],
        ]);

        $phase->update($validated);

        return $phase;
    }

    public function destroy(Phase $phase)
    {
        $this->authorize('delete', $phase->category->competition);

        $phase->delete();

        return response()->noContent();
    }
}
