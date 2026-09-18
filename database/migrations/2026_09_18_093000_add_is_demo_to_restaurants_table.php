<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the App Store / Play Store review restaurant so it can be hidden from
 * every listing — public feeds, search and the admin dashboard — with one env
 * switch (DEMO_RESTAURANT_VISIBLE). Default false: a real diner must never be
 * able to book a table that nobody will honour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false)->after('is_featured');
            $table->index('is_demo');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};
