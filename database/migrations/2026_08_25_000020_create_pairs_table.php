<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Pareja FIJA: se usa para inscripciones en torneos y en ligas con
// modalidad "pareja fija". Las ligas con rotación NO usan esta tabla
// para los partidos (ver matchday_pairings) -- solo, opcionalmente,
// para la inscripción individual de cada jugador (pair_id queda null).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player2_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['player1_id', 'player2_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pairs');
    }
};
