<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_special_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('date');
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->unique(['restaurant_id', 'date']);
        });

        Schema::create('restaurant_special_day_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_special_day_id')->constrained('restaurant_special_days')->cascadeOnDelete();
            $table->foreignId('restaurant_meal_type_id')->constrained('restaurant_meal_types')->cascadeOnDelete();
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_special_day_shifts');
        Schema::dropIfExists('restaurant_special_days');
    }
};
