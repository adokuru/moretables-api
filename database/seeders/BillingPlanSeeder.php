<?php

namespace Database\Seeders;

use App\Models\BillingPlan;
use Illuminate\Database\Seeder;

class BillingPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect(config('billing.plans'))->each(function (array $plan, int $index): void {
            BillingPlan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                [
                    'name' => $plan['name'],
                    'description' => $plan['description'] ?? null,
                    'amount' => $plan['amount'],
                    'currency' => $plan['currency'] ?? 'NGN',
                    'interval' => $plan['interval'] ?? 'monthly',
                    'max_restaurants' => $plan['max_restaurants'] ?? null,
                    'provider' => $plan['provider'] ?? 'paystack',
                    'provider_plan_code' => $plan['provider_plan_code'] ?? null,
                    'features' => $plan['features'] ?? [],
                    'metadata' => $plan['metadata'] ?? [],
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ],
            );
        });
    }
}
