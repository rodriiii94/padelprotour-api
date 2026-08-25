<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SOLO para ligas en modalidad "individual con rotación": registra con
// qué compañero juega cada jugador en una jornada (fase de tipo
// "matchday") concreta. En ligas de pareja fija y en torneos esta tabla
// no se usa -- el emparejamiento ya viene fijado por `pairs`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matchday_pairings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phase_id')->constrained('phases')->cascadeOnDelete();
            $table->foreignId('player1_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('player2_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['phase_id', 'player1_id']);
            $table->unique(['phase_id', 'player2_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matchday_pairings');
    }
};
