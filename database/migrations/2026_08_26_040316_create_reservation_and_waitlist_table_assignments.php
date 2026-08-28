<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('reservation_table_assignments', function (Blueprint $table) {
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained()->cascadeOnDelete();
            $table->primary(['reservation_id', 'restaurant_table_id']);
            $table->index('restaurant_table_id');
        });

        Schema::create('waitlist_table_assignments', function (Blueprint $table) {
            $table->foreignId('waitlist_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_table_id')->constrained()->cascadeOnDelete();
            $table->primary(['waitlist_entry_id', 'restaurant_table_id']);
            $table->index('restaurant_table_id');
        });

        DB::table('reservations')
            ->whereNotNull('restaurant_table_id')
            ->orderBy('id')
            ->chunkById(500, function ($reservations): void {
                DB::table('reservation_table_assignments')->insertOrIgnore(
                    $reservations->map(fn ($reservation): array => [
                        'reservation_id' => $reservation->id,
                        'restaurant_table_id' => $reservation->restaurant_table_id,
                    ])->all(),
                );
            });

        DB::table('waitlist_entries')
            ->whereNotNull('restaurant_table_id')
            ->orderBy('id')
            ->chunkById(500, function ($entries): void {
                DB::table('waitlist_table_assignments')->insertOrIgnore(
                    $entries->map(fn ($entry): array => [
                        'waitlist_entry_id' => $entry->id,
                        'restaurant_table_id' => $entry->restaurant_table_id,
                    ])->all(),
                );
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('waitlist_table_assignments');
        Schema::dropIfExists('reservation_table_assignments');
    }
};
