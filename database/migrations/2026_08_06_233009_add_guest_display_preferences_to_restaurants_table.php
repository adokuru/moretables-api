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
        Schema::table('restaurants', function (Blueprint $table) {
            // Default true: preserves current behavior (full name shown everywhere) until staff opts to shorten it.
            $table->boolean('display_guest_full_name')->default(true)->after('display_recommended_table_assignment');
            // Default false: a new badge that didn't exist before this preference — opt-in rather than
            // surfacing new guest-profile UI unprompted for every existing restaurant.
            $table->boolean('show_guest_preferences')->default(false)->after('display_guest_full_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['display_guest_full_name', 'show_guest_preferences']);
        });
    }
};
