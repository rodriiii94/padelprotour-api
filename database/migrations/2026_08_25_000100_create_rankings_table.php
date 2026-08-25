<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Igual que en registrations: exactamente uno de pair_id / player_id
// está relleno, según si la categoría es de pareja fija o individual
// con rotación (en ese caso el ranking se lleva por jugador).
// Se recalcula (no se acumula in-place) para poder mantener histórico:
// cada fila es una "foto" del ranking en `calculated_at`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rankings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pair_id')->nullable()->constrained('pairs')->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->integer('points')->default(0);
            $table->unsignedInteger('position')->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rankings');
    }
};
