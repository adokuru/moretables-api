<?php

namespace Database\Factories;

use App\BillingPlanSlug;
use App\Models\BillingPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingPlan>
 */
class BillingPlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = $this->resolveAvailableSlug();

        return [
            'name' => str($slug->value)->headline()->toString(),
            'slug' => $slug,
            'description' => $this->faker->sentence(),
            'amount' => $this->faker->randomElement([8500000, 13500000, 18500000]),
            'currency' => 'NGN',
            'interval' => 'monthly',
            'provider' => 'paystack',
            'provider_plan_code' => 'PLN_'.$this->faker->unique()->bothify('????######'),
            'features' => [
                'reservations' => true,
                'floor_plan' => true,
            ],
            'metadata' => [],
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(1, 10),
        ];
    }

    private function resolveAvailableSlug(): BillingPlanSlug
    {
        foreach (BillingPlanSlug::cases() as $case) {
            if (! BillingPlan::query()->where('slug', $case->value)->exists()) {
                return $case;
            }
        }

        return BillingPlanSlug::Foundation;
    }
}
