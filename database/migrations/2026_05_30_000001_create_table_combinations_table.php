<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('table_combinations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dining_area_id')->nullable()->constrained()->nullOnDelete();
            $table->json('table_ids');       // sorted array of RestaurantTable IDs
            $table->unsignedInteger('min_capacity')->default(1);
            $table->unsignedInteger('max_capacity')->default(1);
            $table->timestamps();

            $table->index(['restaurant_id', 'dining_area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_combinations');
    }
};
