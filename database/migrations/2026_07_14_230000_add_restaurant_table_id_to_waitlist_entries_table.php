<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->foreignId('restaurant_table_id')
                ->nullable()
                ->after('reservation_id')
                ->constrained('restaurant_tables')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('restaurant_table_id');
        });
    }
};
