<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            // MySQL cannot drop a unique index that is the sole index supporting a FK.
            // Drop the FK first, then drop the unique, add a plain index so the FK
            // has backing support, re-add the FK, then create the new unique.
            $table->dropForeign(['restaurant_id']);
            $table->dropUnique(['restaurant_id', 'name']);
            $table->index('restaurant_id'); // backing index for the FK
            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();

            $table->unique(['dining_area_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropUnique(['dining_area_id', 'name']);

            $table->dropForeign(['restaurant_id']);
            $table->dropIndex(['restaurant_id']);
            $table->unique(['restaurant_id', 'name']); // restores the composite unique as the backing index
            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
        });
    }
};
