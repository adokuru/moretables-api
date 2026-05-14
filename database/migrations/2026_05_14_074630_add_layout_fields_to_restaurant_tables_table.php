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
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->string('table_type')->default('regular')->after('max_capacity');
            $table->string('shape')->default('rectangle')->after('table_type');
            $table->unsignedInteger('x_position')->nullable()->after('sort_order');
            $table->unsignedInteger('y_position')->nullable()->after('x_position');
            $table->unsignedInteger('width')->nullable()->after('y_position');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->unsignedSmallInteger('rotation')->default(0)->after('height');
            $table->string('color', 20)->nullable()->after('rotation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropColumn([
                'table_type',
                'shape',
                'x_position',
                'y_position',
                'width',
                'height',
                'rotation',
                'color',
            ]);
        });
    }
};
