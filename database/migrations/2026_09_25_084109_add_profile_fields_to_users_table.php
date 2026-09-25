<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('name_changed_at')->nullable();
            $table->string('bio', 200)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('preferred_side')->nullable();
            $table->string('dominant_hand')->nullable();
            $table->string('avatar_color')->nullable();
            $table->string('avatar_emoji', 16)->nullable();
            $table->string('racket', 80)->nullable();
            $table->string('motto', 80)->nullable();
            $table->json('availability')->nullable();
            $table->json('social_links')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'name_changed_at',
                'bio',
                'city',
                'preferred_side',
                'dominant_hand',
                'avatar_color',
                'avatar_emoji',
                'racket',
                'motto',
                'availability',
                'social_links',
            ]);
        });
    }
};
