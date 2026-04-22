<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_reviews', function (Blueprint $table): void {
            $table->json('review_images')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_reviews', function (Blueprint $table): void {
            $table->dropColumn('review_images');
        });
    }
};
