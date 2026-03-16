<?php

use App\UserAuthMethod;
use App\UserStatus;
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
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('phone')->nullable()->unique()->after('email');
            $table->string('status')->default(UserStatus::Active->value)->after('password');
            $table->string('auth_method')->default(UserAuthMethod::Password->value)->after('status');
            $table->timestamp('last_active_at')->nullable()->after('remember_token');
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn([
                'first_name',
                'last_name',
                'phone',
                'status',
                'auth_method',
                'last_active_at',
            ]);
            $table->string('password')->nullable(false)->change();
        });
    }
};
