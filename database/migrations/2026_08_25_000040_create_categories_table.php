<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// registration_mode solo aplica a ligas (torneos son siempre pareja fija,
// se ignora / queda null para type = tournament). Se valida en la app
// que un torneo nunca tenga registration_mode = individual_rotating.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('competition_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // p.ej. "3ª Mixta"
            $table->string('match_format')->nullable(); // p.ej. "3 sets, super tie-break"
            $table->unsignedInteger('slots')->nullable(); // nº de parejas/jugadores admitidos
            $table->string('registration_mode')->nullable(); // fixed_pair | individual_rotating (solo ligas)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
