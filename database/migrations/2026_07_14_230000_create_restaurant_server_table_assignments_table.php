<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_server_table_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_server_id')->constrained()->cascadeOnDelete();
            $table->dateTimeTz('service_starts_at');
            $table->dateTimeTz('service_ends_at');
            $table->timestamps();

            $table->unique(
                ['restaurant_table_id', 'service_starts_at'],
                'server_table_shift_unique',
            );
            $table->index(
                ['restaurant_id', 'service_starts_at', 'service_ends_at'],
                'server_assignment_service_window_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_server_table_assignments');
    }
};
