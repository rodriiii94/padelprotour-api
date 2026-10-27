<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Hueco del partido dentro de su ronda de playoff (0, 1, 2...): los ganadores de los
            // huecos 2n y 2n+1 se cruzan en el hueco n de la ronda siguiente. Null fuera del playoff.
            $table->unsignedSmallInteger('bracket_position')->nullable()->after('phase_id');
            // Un único partido por hueco: si dos resultados se confirman a la vez, no se duplica
            // el cruce de la ronda siguiente.
            $table->unique(['phase_id', 'bracket_position']);
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropUnique(['phase_id', 'bracket_position']);
            $table->dropColumn('bracket_position');
        });
    }
};
