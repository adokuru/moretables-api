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
            // Default false: a finished guest whose table has been marked
            // Cleaned is hidden from the Finished section by default (see
            // AdminFrontLeft.tsx/list-view/page.tsx/HomeLeft.tsx) — this is
            // opt-in visibility, not a new default, matching
            // show_guest_preferences' own "new UI, opt-in" precedent.
            $table->boolean('show_cleaned_tables')->default(false)->after('show_guest_preferences');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('show_cleaned_tables');
        });
    }
};
