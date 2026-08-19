<?php

namespace Database\Factories;

use App\MerchantInvoiceStatus;
use App\Models\BillingPlan;
use App\Models\MerchantInvoice;
use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantInvoice>
 */
class MerchantInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'organization_id' => fn (array $attributes): ?int => Restaurant::query()
                ->whereKey($attributes['restaurant_id'])
                ->value('organization_id'),
            'billing_plan_id' => fn () => BillingPlan::query()->value('id') ?? BillingPlan::factory()->create()->id,
            'invoice_number' => 'MT-INV-'.$this->faker->unique()->numerify('######'),
            'provider' => 'paystack',
            'provider_reference' => 'mt_'.$this->faker->unique()->bothify('????????'),
            'receipt_number' => null,
            'amount' => $this->faker->randomElement([8500000, 13500000, 18500000]),
            'currency' => 'NGN',
            'status' => MerchantInvoiceStatus::Pending,
            'billing_period_start' => now()->startOfMonth(),
            'billing_period_end' => now()->endOfMonth(),
            'due_at' => now()->addDay(),
            'metadata' => [],
        ];
    }

    /**
     * A business-level record: owned by the organization, inherited by its restaurants.
     */
    public function forBusiness(Organization $organization): static
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->id,
            'restaurant_id' => null,
        ]);
    }
}
