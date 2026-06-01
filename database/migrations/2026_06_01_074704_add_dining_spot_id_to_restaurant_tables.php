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
        Schema::table('restaurant_tables', function (Blueprint $table): void {
            $table->foreignId('dining_spot_id')->nullable()->after('dining_area_id')->constrained('dining_spots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table): void {
            $table->dropForeign(['dining_spot_id']);
            $table->dropColumn('dining_spot_id');
        });
    }
};
