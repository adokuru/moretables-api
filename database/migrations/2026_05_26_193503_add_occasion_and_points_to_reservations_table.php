<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('occasion')->nullable()->after('notes');
            $table->boolean('accept_points')->default(false)->after('occasion');
            $table->boolean('subscribe_to_promotions')->default(false)->after('accept_points');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropColumn(['occasion', 'accept_points', 'subscribe_to_promotions']);
        });
    }
};
