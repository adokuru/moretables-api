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
            $table->decimal('x_position', 6, 1)->nullable()->change();
            $table->decimal('y_position', 6, 1)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table): void {
            $table->unsignedInteger('x_position')->nullable()->change();
            $table->unsignedInteger('y_position')->nullable()->change();
        });
    }
};
