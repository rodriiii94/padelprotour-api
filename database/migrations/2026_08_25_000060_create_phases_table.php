<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Una "fase" agrupa partidos: una ronda de eliminatoria (Cuartos, Semis...),
// un grupo de la fase previa, o una jornada de liga. `order` define el
// orden de progresión (jornada 1, 2, 3... o Cuartos -> Semis -> Final).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // group | elimination_round | matchday
            $table->string('name'); // p.ej. "Cuartos de final", "Jornada 4"
            $table->unsignedInteger('order');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phases');
    }
};
