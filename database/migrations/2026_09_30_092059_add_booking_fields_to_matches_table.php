<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Reserva de pista que hacen los jugadores (normalmente en Playtomic): el club y el
            // enlace al partido de Playtomic. Día/hora y pista van en `scheduled_at` y `court`.
            $table->string('club', 120)->nullable()->after('court');
            $table->string('playtomic_url', 255)->nullable()->after('club');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['club', 'playtomic_url']);
        });
    }
};
