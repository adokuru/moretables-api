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
            $table->foreignId('assigned_server_id')
                ->nullable()
                ->after('status')
                ->constrained('restaurant_servers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_server_id');
        });
    }
};
