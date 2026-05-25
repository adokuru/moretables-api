<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dining_areas', function (Blueprint $table): void {
            $table->string('category')->nullable()->after('name');
            $table->string('floor_type')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('dining_areas', function (Blueprint $table): void {
            $table->dropColumn(['category', 'floor_type']);
        });
    }
};
