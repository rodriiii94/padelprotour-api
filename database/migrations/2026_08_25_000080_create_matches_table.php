<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Un partido de pádel siempre enfrenta a 2 jugadores contra otros 2,
// tanto si vienen de una pareja fija (torneos, ligas de pareja fija)
// como de un emparejamiento de jornada (ligas con rotación). Por eso
// `matches` guarda directamente los 4 jugadores en vez de referenciar
// `pairs` -- evita modelar dos casos distintos y simplifica las
// consultas de "próximos partidos de un jugador".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phase_id')->constrained('phases')->cascadeOnDelete();

            $table->foreignId('side1_player1_id')->constrained('users');
            $table->foreignId('side1_player2_id')->constrained('users');
            $table->foreignId('side2_player1_id')->constrained('users');
            $table->foreignId('side2_player2_id')->constrained('users');

            $table->timestamp('scheduled_at')->nullable();
            $table->string('court')->nullable();
            $table->string('status')->default('scheduled'); // scheduled | in_progress | pending_validation | completed
            $table->unsignedTinyInteger('winner_side')->nullable(); // 1 | 2, una vez validado
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
