<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            // Solo tiene efecto en competiciones de tipo liga: si está activo, el calendario
            // generado juega ida y vuelta (cada pareja se enfrenta dos veces).
            $table->boolean('double_round')->default(false)->after('is_private');
        });
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->dropColumn('double_round');
        });
    }
};
