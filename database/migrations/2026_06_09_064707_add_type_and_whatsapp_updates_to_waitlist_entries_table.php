<?php

use App\WaitlistType;
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
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->string('type')->default(WaitlistType::Seating->value)->after('status')->index();
            $table->boolean('whatsapp_updates')->default(false)->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('waitlist_entries', function (Blueprint $table): void {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'whatsapp_updates']);
        });
    }
};
