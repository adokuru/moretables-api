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
        Schema::table('restaurant_reviews', function (Blueprint $table) {
            $table->decimal('rating', total: 3, places: 2)->change();
            $table->unsignedTinyInteger('food_rating')->nullable()->after('rating');
            $table->unsignedTinyInteger('service_rating')->nullable()->after('food_rating');
            $table->unsignedTinyInteger('ambience_rating')->nullable()->after('service_rating');
            $table->unsignedTinyInteger('value_rating')->nullable()->after('ambience_rating');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->change();
            $table->dropColumn([
                'food_rating',
                'service_rating',
                'ambience_rating',
                'value_rating',
            ]);
        });
    }
};
