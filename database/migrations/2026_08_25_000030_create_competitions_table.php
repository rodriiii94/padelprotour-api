<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `type` y otros campos de estado se guardan como string (no enum nativo
// de PostgreSQL): un enum nativo en Postgres es incómodo de modificar más
// adelante (ALTER TYPE ... ADD VALUE tiene restricciones dentro de
// transacciones). Se valida en la capa de aplicación (p.ej. un enum PHP
// + $casts en el modelo Eloquent) en su lugar.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // tournament | league
            $table->string('name');
            $table->string('venue')->nullable(); // sede/club, texto libre
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('registration_closes_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};
