<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Exactamente uno de pair_id / player_id está relleno, según la
// modalidad de la categoría: pair_id para pareja fija, player_id para
// ligas individuales con rotación. Se valida en la app (constraint CHECK
// opcional a nivel de base de datos si se quiere reforzar más adelante).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pair_id')->nullable()->constrained('pairs')->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending | confirmed | waitlisted | rejected
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
