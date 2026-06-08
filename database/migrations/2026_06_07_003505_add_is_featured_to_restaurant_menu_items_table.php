<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('restaurant_menu_items', 'is_featured')) {
            return;
        }

        Schema::table('restaurant_menu_items', function (Blueprint $table): void {
            $table->boolean('is_featured')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('restaurant_menu_items', 'is_featured')) {
            return;
        }

        Schema::table('restaurant_menu_items', function (Blueprint $table): void {
            $table->dropColumn('is_featured');
        });
    }
};
