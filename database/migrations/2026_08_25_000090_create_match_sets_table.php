<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// El "Resultado" de la especificación se modela como una fila por set
// (marcador set a set), en vez de un único campo de texto -- necesario
// para poder mostrar el marcador set a set en la pantalla de partido.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->unsignedTinyInteger('set_number');
            $table->unsignedTinyInteger('side1_games');
            $table->unsignedTinyInteger('side2_games');
            $table->timestamps();

            $table->unique(['match_id', 'set_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_sets');
    }
};
