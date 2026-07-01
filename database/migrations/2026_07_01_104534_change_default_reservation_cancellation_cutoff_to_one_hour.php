<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('restaurant_policies', function (Blueprint $table) {
            $table->unsignedInteger('cancellation_cutoff_hours')->default(1)->change();
        });

        DB::table('restaurant_policies')
            ->where('cancellation_cutoff_hours', 24)
            ->update(['cancellation_cutoff_hours' => 1]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('restaurant_policies')
            ->where('cancellation_cutoff_hours', 1)
            ->update(['cancellation_cutoff_hours' => 24]);

        Schema::table('restaurant_policies', function (Blueprint $table) {
            $table->unsignedInteger('cancellation_cutoff_hours')->default(24)->change();
        });
    }
};
