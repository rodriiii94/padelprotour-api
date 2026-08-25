<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Ranking;

class RankingController extends Controller
{
    public function index(Category $category)
    {
        $this->authorize('view', $category->competition);

        return $category->rankings()->orderBy('position')->get();
    }

    public function recalculate(Category $category)
    {
        $this->authorize('update', $category->competition);

        return Ranking::recalculateForCategory($category)->values();
    }
}
