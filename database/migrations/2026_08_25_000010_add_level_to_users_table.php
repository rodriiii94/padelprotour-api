<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Extiende la tabla `users` que ya trae Laravel de serie (auth/Sanctum).
// Un mismo usuario puede ser jugador y organizador: no hay tabla de roles
// separada, cualquier usuario puede crear competiciones y/o inscribirse.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('level')->nullable()->after('email'); // p.ej. "3ª", "4ª"
            $table->string('club')->nullable()->after('level'); // club habitual, texto libre (no hay entidad Club)
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['level', 'club']);
        });
    }
};
