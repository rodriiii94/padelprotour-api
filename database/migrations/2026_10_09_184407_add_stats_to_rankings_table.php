<?php

use App\Models\Category;
use App\Models\Ranking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rankings', function (Blueprint $table) {
            // Lo que ya se calculaba para ordenar la clasificación pero no se guardaba.
            $table->unsignedInteger('played')->default(0)->after('points');
            $table->unsignedInteger('won')->default(0)->after('played');
            $table->unsignedInteger('lost')->default(0)->after('won');
            $table->unsignedInteger('sets_won')->default(0)->after('lost');
            $table->unsignedInteger('sets_lost')->default(0)->after('sets_won');
            $table->unsignedInteger('games_won')->default(0)->after('sets_lost');
            $table->unsignedInteger('games_lost')->default(0)->after('games_won');
        });

        // Las clasificaciones son una foto recalculable: se rehacen para que las existentes
        // tengan ya las estadísticas (y a los inscritos que aún no han jugado).
        Category::query()->each(fn (Category $category) => Ranking::recalculateForCategory($category));
    }

    public function down(): void
    {
        Schema::table('rankings', function (Blueprint $table) {
            $table->dropColumn(['played', 'won', 'lost', 'sets_won', 'sets_lost', 'games_won', 'games_lost']);
        });
    }
};
