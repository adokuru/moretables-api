<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('service_stage')->nullable()->after('status');
            $table->index(['restaurant_id', 'service_stage']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex(['restaurant_id', 'service_stage']);
            $table->dropColumn('service_stage');
        });
    }
};
