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
        Schema::table('user_roles', function (Blueprint $table): void {
            $table->foreignId('access_config_id')
                ->nullable()
                ->after('role_id')
                ->constrained('restaurant_access_configs')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_roles', function (Blueprint $table): void {
            $table->dropForeign(['access_config_id']);
            $table->dropColumn('access_config_id');
        });
    }
};
