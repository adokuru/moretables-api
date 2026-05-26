<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->boolean('rewards_enabled')->default(true)->after('is_featured');
            $table->unsignedSmallInteger('reservation_reward_points')->default(100)->after('rewards_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn(['rewards_enabled', 'reservation_reward_points']);
        });
    }
};
