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
        Schema::table('guest_contacts', function (Blueprint $table) {
            $table->boolean('marketing_opt_in')->default(false)->after('notes');
            $table->date('birthday')->nullable()->after('marketing_opt_in');
            $table->date('wedding_anniversary')->nullable()->after('birthday');
            $table->json('preferences')->nullable()->after('wedding_anniversary');
        });
    }

    public function down(): void
    {
        Schema::table('guest_contacts', function (Blueprint $table) {
            $table->dropColumn(['marketing_opt_in', 'birthday', 'wedding_anniversary', 'preferences']);
        });
    }
};
