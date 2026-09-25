<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Cuándo se propuso el resultado: de aquí salen las 48 h para confirmarse solo.
            $table->timestamp('result_proposed_at')->nullable()->after('result_proposed_by');
        });

        // Propuestas ya pendientes antes de esta migración: cuentan desde ahora.
        DB::table('matches')->where('status', 'pending_validation')->update(['result_proposed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('result_proposed_at');
        });
    }
};
