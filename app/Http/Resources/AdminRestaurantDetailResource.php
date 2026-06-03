<?php

namespace App\Http\Resources;

use App\Models\MerchantPaymentMethod;
use App\Models\MerchantSubscription;
use Illuminate\Http\Request;

class AdminRestaurantDetailResource extends RestaurantDetailResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        if ($request->user()?->requiresAdminLogin()) {
            $data['internal_notes'] = $this->internal_notes;
        }

        return array_merge($data, [
            'rewards_enabled' => (bool) $this->rewards_enabled,
            'reservation_reward_points' => $this->reservation_reward_points,
            'contact_email_verified_at' => $this->contact_email_verified_at?->toIso8601String(),
            'contact_phone_verified_at' => $this->contact_phone_verified_at?->toIso8601String(),
            'onboarding_last_step' => $this->onboarding_last_step,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'cuisine_options' => $this->whenLoaded('cuisines', fn () => $this->cuisines->map(fn ($cuisine): array => [
                'id' => $cuisine->id,
                'name' => $cuisine->name,
                'slug' => $cuisine->slug,
                'is_primary' => (bool) ($cuisine->pivot?->is_primary ?? false),
            ])->values()),
            'social_handles' => $this->whenLoaded('socialHandles', fn () => $this->socialHandles->map(fn ($handle): array => [
                'platform' => $handle->platform,
                'handle' => $handle->handle,
            ])->values()),
            'special_days' => RestaurantSpecialDayResource::collection($this->whenLoaded('specialDays')),
            'table_combinations' => TableCombinationResource::collection($this->whenLoaded('tableCombinations')),
            'guest_communication_setting' => $this->whenLoaded(
                'guestCommunicationSetting',
                fn () => $this->guestCommunicationSetting
                    ? GuestCommunicationSettingResource::make($this->guestCommunicationSetting)
                    : null,
            ),
            'reward_rules' => RestaurantRewardRuleResource::collection($this->whenLoaded('rewardRules')),
            'access_configs' => RestaurantAccessConfigResource::collection($this->whenLoaded('accessConfigs')),
            'staff' => RestaurantStaffAssignmentResource::collection($this->whenLoaded('userRoles')),
            'active_billing_subscription' => $this->whenLoaded(
                'activeBillingSubscription',
                fn () => $this->activeBillingSubscription
                    ? $this->subscriptionPayload($this->activeBillingSubscription)
                    : null,
            ),
            'latest_billing_subscription' => $this->whenLoaded(
                'latestBillingSubscription',
                fn () => $this->latestBillingSubscription
                    ? $this->subscriptionPayload($this->latestBillingSubscription)
                    : null,
            ),
            'default_payment_method' => $this->whenLoaded(
                'defaultPaymentMethod',
                fn () => $this->defaultPaymentMethod
                    ? $this->paymentMethodPayload($this->defaultPaymentMethod)
                    : null,
            ),
            'stats' => $this->when(
                $this->resource->getAttribute('waitlist_entries_count') !== null,
                fn (): array => [
                    'waitlist_entries_count' => (int) $this->resource->getAttribute('waitlist_entries_count'),
                    'guest_contacts_count' => (int) $this->resource->getAttribute('guest_contacts_count'),
                ],
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function subscriptionPayload(MerchantSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'provider' => $subscription->provider?->value,
            'status' => $subscription->status?->value,
            'provider_subscription_code' => $subscription->provider_subscription_code,
            'current_period_start' => $subscription->current_period_start?->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'next_payment_at' => $subscription->next_payment_at?->toIso8601String(),
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'canceled_at' => $subscription->canceled_at?->toIso8601String(),
            'metadata' => $subscription->metadata,
            'plan' => $subscription->relationLoaded('plan') && $subscription->plan ? [
                'id' => $subscription->plan->id,
                'name' => $subscription->plan->name,
                'slug' => $subscription->plan->slug?->value,
                'amount' => $subscription->plan->amount,
                'currency' => $subscription->plan->currency,
                'interval' => $subscription->plan->interval,
            ] : null,
            'created_at' => $subscription->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function paymentMethodPayload(MerchantPaymentMethod $paymentMethod): array
    {
        return [
            'id' => $paymentMethod->id,
            'provider' => $paymentMethod->provider?->value,
            'brand' => $paymentMethod->brand,
            'card_type' => $paymentMethod->card_type,
            'last4' => $paymentMethod->last4,
            'exp_month' => $paymentMethod->exp_month,
            'exp_year' => $paymentMethod->exp_year,
            'bank' => $paymentMethod->bank,
            'channel' => $paymentMethod->channel,
            'email' => $paymentMethod->email,
            'is_default' => (bool) $paymentMethod->is_default,
        ];
    }
}
