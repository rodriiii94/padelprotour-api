<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Competition;
use Closure;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Competition $competition)
    {
        $this->authorize('view', $competition);

        return $competition->categories;
    }

    public function store(Request $request, Competition $competition)
    {
        $this->authorize('update', $competition);

        $validated = $this->validated($request, $competition);

        $category = $competition->categories()->create($validated);

        return response()->json($category, 201);
    }

    public function show(Category $category)
    {
        $this->authorize('view', $category->competition);

        return $category;
    }

    public function update(Request $request, Category $category)
    {
        $this->authorize('update', $category->competition);

        $validated = $this->validated($request, $category->competition, sometimes: true);

        $category->update($validated);

        return $category;
    }

    public function destroy(Category $category)
    {
        $this->authorize('update', $category->competition);

        $category->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Competition $competition, bool $sometimes = false): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'match_format' => ['nullable', 'string', 'max:255'],
            'slots' => ['nullable', 'integer', 'min:1'],
            'registration_mode' => [
                'nullable',
                'string',
                'in:fixed_pair,individual_rotating',
                function (string $attribute, mixed $value, Closure $fail) use ($competition): void {
                    // Los torneos son siempre de pareja fija: nunca admiten rotación individual.
                    if ($value === 'individual_rotating' && $competition->type === 'tournament') {
                        $fail('Un torneo no puede tener una categoría con rotación individual.');
                    }
                },
            ],
        ]);
    }
}
