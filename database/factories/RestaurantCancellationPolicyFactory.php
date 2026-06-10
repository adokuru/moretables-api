<?php

namespace Database\Factories;

use App\Enums\CancellationPolicyManagementMethod;
use App\Enums\CancellationPolicyPartySizeScope;
use App\Models\Restaurant;
use App\Models\RestaurantCancellationPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestaurantCancellationPolicy>
 */
class RestaurantCancellationPolicyFactory extends Factory
{
    protected $model = RestaurantCancellationPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'name' => fake()->words(3, true),
            'management_method' => CancellationPolicyManagementMethod::CardHold,
            'party_size_scope' => CancellationPolicyPartySizeScope::AllPartySizes,
            'min_party_size' => null,
            'max_party_size' => null,
            'hold_charge_amount' => 5000,
            'starts_on' => null,
            'ends_on' => null,
            'days' => [1],
            'start_time' => null,
            'end_time' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function withTimeWindow(string $startTime, string $endTime): static
    {
        return $this->state(fn (): array => [
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }

    public function forCustomPartySize(int $min, int $max): static
    {
        return $this->state(fn (): array => [
            'party_size_scope' => CancellationPolicyPartySizeScope::Custom,
            'min_party_size' => $min,
            'max_party_size' => $max,
        ]);
    }
}
