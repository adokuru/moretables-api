<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing moves from the restaurant to the business (organization) that owns it: a business
     * holds one subscription and its restaurants inherit it. Existing restaurant-scoped rows keep
     * their restaurant_id and are backfilled with the owning organization, so nothing already sold
     * changes shape — business-level rows are the ones that carry a null restaurant_id.
     *
     * @var list<string>
     */
    protected array $tables = [
        'merchant_subscriptions',
        'merchant_invoices',
        'merchant_payments',
        'merchant_payment_methods',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('organization_id')
                    ->nullable()
                    ->after('id')
                    ->constrained()
                    ->cascadeOnDelete();
            });

            DB::statement("update {$tableName} set organization_id = (select organization_id from restaurants where restaurants.id = {$tableName}.restaurant_id) where restaurant_id is not null");

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('restaurant_id')->nullable()->change();
            });
        }

        Schema::table('merchant_subscriptions', function (Blueprint $table): void {
            $table->index(['organization_id', 'status']);
        });

        Schema::table('merchant_invoices', function (Blueprint $table): void {
            $table->index(['organization_id', 'status']);
        });

        Schema::table('merchant_payments', function (Blueprint $table): void {
            $table->index(['organization_id', 'status']);
        });

        Schema::table('merchant_payment_methods', function (Blueprint $table): void {
            $table->index(['organization_id', 'is_default']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchant_subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'status']);
        });

        Schema::table('merchant_invoices', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'status']);
        });

        Schema::table('merchant_payments', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'status']);
        });

        Schema::table('merchant_payment_methods', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'is_default']);
        });

        foreach ($this->tables as $tableName) {
            DB::table($tableName)->whereNull('restaurant_id')->delete();

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('organization_id');
                $table->foreignId('restaurant_id')->nullable(false)->change();
            });
        }
    }
};
