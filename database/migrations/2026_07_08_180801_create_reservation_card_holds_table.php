<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_card_holds', function (Blueprint $table): void {
            $table->id();
            // Card is captured before the reservation exists, so the reservation link is set later.
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('paystack');
            $table->string('reference')->unique();
            $table->string('authorization_code')->nullable();
            $table->string('email');
            $table->string('brand')->nullable();
            $table->string('last4', 4)->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('NGN');
            $table->string('charge_reference')->nullable()->unique();
            $table->unsignedInteger('charged_amount')->nullable();
            $table->timestamp('charged_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['reservation_id', 'status']);
            $table->index(['user_id', 'restaurant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_card_holds');
    }
};
