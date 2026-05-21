<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->string('layout_type', 50)->nullable()->after('color');
            $table->string('chair_color', 20)->nullable()->after('layout_type');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropColumn(['layout_type', 'chair_color']);
        });
    }
};
