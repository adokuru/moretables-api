<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Laravel Pulse was removed (incompatible with Laravel 13). This migration is retained
     * so environments that already ran the original Pulse migration keep a valid history.
     */
    public function up(): void {}

    public function down(): void {}
};
