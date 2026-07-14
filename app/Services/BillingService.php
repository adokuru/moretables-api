<?php

namespace App\Services;

use App\BillingProvider;
use App\Contracts\PaymentProvider;
use App\MerchantInvoiceStatus;
use App\MerchantPaymentStatus;
use App\MerchantSubscriptionStatus;
use App\Models\BillingPlan;
use App\Models\MerchantInvoice;
use App\Models\MerchantPayment;
use App\Models\MerchantPaymentMethod;
use App\Models\MerchantSubscription;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\MerchantPaymentConfirmationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class BillingService
{
    public function __construct(
        protected PaymentProvider $provider,
        protected PerformanceCacheService $performanceCache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function initializeCheckout(Restaurant $restaurant, BillingPlan $plan, bool $isUpgrade = false, ?string $requesterEmail = null): array
    {
        $invoice = MerchantInvoice::query()->create([
            'restaurant_id' => $restaurant->id,
            'billing_plan_id' => $plan->id,
            'invoice_number' => $this->generateInvoiceNumber(),
            'provider' => BillingProvider::Paystack,
            'provider_reference' => 'mt_'.strtolower((string) Str::ulid()),
            'amount' => $plan->amount,
            'currency' => $plan->currency,
            'status' => MerchantInvoiceStatus::Pending,
            'billing_period_start' => now(),
            'billing_period_end' => now()->addMonth(),
            'due_at' => now()->addDay(),
            'metadata' => $isUpgrade ? ['is_upgrade' => true] : null,
        ]);

        $providerResponse = $this->provider->initializeSubscriptionCheckout($restaurant, $plan, $invoice, $requesterEmail);

        return [
            'reference' => $invoice->provider_reference,
            'invoice' => $invoice,
            'provider_response' => $providerResponse,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyCheckout(Restaurant $restaurant, string $reference): array
    {
        $response = $this->provider->verifyTransaction($reference);
        $data = Arr::get($response, 'data', []);

        $invoice = MerchantInvoice::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('provider_reference', $reference)
            ->firstOrFail();

        $payment = $this->recordTransaction($invoice, $data);

        return [
            'invoice' => $invoice->refresh()->loadMissing(['plan', 'payments.paymentMethod']),
            'payment' => $payment,
            'provider_response' => $response,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handlePaystackWebhook(array $payload): void
    {
        $webhook = $this->provider->handleWebhook($payload);
        $event = $webhook['event'] ?? null;
        $data = is_array($webhook['data'] ?? null) ? $webhook['data'] : [];

        if ($event === 'charge.success') {
            $reference = Arr::get($data, 'reference');
            $invoice = MerchantInvoice::query()
                ->where('provider_reference', $reference)
                ->first();

            // No matching invoice means this is a subscription renewal charge.
            // Create an invoice so the payment is recorded against the subscription.
            if (! $invoice) {
                $subscriptionCode = Arr::get($data, 'subscription.subscription_code')
                    ?? Arr::get($data, 'subscription_code');

                if ($subscriptionCode) {
                    $subscription = MerchantSubscription::query()
                        ->with('plan')
                        ->where('provider_subscription_code', $subscriptionCode)
                        ->first();

                    if ($subscription) {
                        $invoice = MerchantInvoice::query()->create([
                            'restaurant_id' => $subscription->restaurant_id,
                            'billing_plan_id' => $subscription->billing_plan_id,
                            'merchant_subscription_id' => $subscription->id,
                            'invoice_number' => $this->generateInvoiceNumber(),
                            'provider' => BillingProvider::Paystack,
                            'provider_reference' => $reference,
                            'amount' => (int) Arr::get($data, 'amount', $subscription->plan?->amount ?? 0),
                            'currency' => Arr::get($data, 'currency', $subscription->plan?->currency ?? 'NGN'),
                            'status' => MerchantInvoiceStatus::Pending,
                            'billing_period_start' => now(),
                            'billing_period_end' => now()->addMonth(),
                            'due_at' => now(),
                        ]);
                    }
                }
            }

            if ($invoice) {
                $this->recordTransaction($invoice, $data);
            }
        }

        if (in_array($event, ['subscription.create', 'subscription.enable'], true)) {
            $this->syncSubscriptionPayload($data, MerchantSubscriptionStatus::Active, cancelAtPeriodEnd: false);
        }

        // not_renew = subscription stays active until period end, then stops (cancel_at_period_end).
        // disable   = immediately canceled.
        if ($event === 'subscription.not_renew') {
            $this->syncSubscriptionPayload($data, MerchantSubscriptionStatus::Active, cancelAtPeriodEnd: true);
        }

        if ($event === 'subscription.disable') {
            $this->syncSubscriptionPayload($data, MerchantSubscriptionStatus::Canceled, cancelAtPeriodEnd: false);
        }
    }

    /**
     * Manually assign a billing subscription to a restaurant (admin override, no Paystack checkout).
     *
     * @param  array{status?: string, current_period_end?: string|null, notes?: string|null, record_invoice?: bool}  $attributes
     * @return array{subscription: MerchantSubscription, invoice: ?MerchantInvoice}
     */
    public function assignAdminSubscription(
        Restaurant $restaurant,
        BillingPlan $plan,
        User $admin,
        array $attributes = [],
    ): array {
        $periodStart = CarbonImmutable::now();
        $periodEnd = isset($attributes['current_period_end'])
            ? CarbonImmutable::parse($attributes['current_period_end'])
            : $this->defaultPeriodEnd($plan, $periodStart);

        $status = isset($attributes['status'])
            ? MerchantSubscriptionStatus::from($attributes['status'])
            : MerchantSubscriptionStatus::Active;

        if (! in_array($status, [MerchantSubscriptionStatus::Active, MerchantSubscriptionStatus::Trialing], true)) {
            $status = MerchantSubscriptionStatus::Active;
        }

        $this->cancelActiveSubscriptions($restaurant);

        $subscriptionCode = 'admin_'.strtolower((string) Str::ulid());

        $subscription = MerchantSubscription::query()->create([
            'restaurant_id' => $restaurant->id,
            'billing_plan_id' => $plan->id,
            'provider' => BillingProvider::Paystack,
            'status' => $status,
            'provider_subscription_code' => $subscriptionCode,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'next_payment_at' => $periodEnd,
            'cancel_at_period_end' => false,
            'metadata' => array_filter([
                'assigned_by_admin_id' => $admin->id,
                'assigned_at' => now()->toIso8601String(),
                'notes' => $attributes['notes'] ?? null,
                'source' => 'admin_assignment',
            ]),
        ]);

        $invoice = null;

        if ($attributes['record_invoice'] ?? true) {
            $invoice = MerchantInvoice::query()->create([
                'restaurant_id' => $restaurant->id,
                'billing_plan_id' => $plan->id,
                'merchant_subscription_id' => $subscription->id,
                'invoice_number' => $this->generateInvoiceNumber(),
                'provider' => BillingProvider::Paystack,
                'provider_reference' => 'admin_'.$subscriptionCode,
                'amount' => $plan->amount,
                'currency' => $plan->currency,
                'status' => MerchantInvoiceStatus::Paid,
                'billing_period_start' => $periodStart,
                'billing_period_end' => $periodEnd,
                'due_at' => $periodStart,
                'paid_at' => $periodStart,
                'metadata' => [
                    'source' => 'admin_assignment',
                    'assigned_by_admin_id' => $admin->id,
                    'notes' => $attributes['notes'] ?? null,
                ],
            ]);

            MerchantPayment::query()->create([
                'restaurant_id' => $restaurant->id,
                'merchant_invoice_id' => $invoice->id,
                'merchant_subscription_id' => $subscription->id,
                'provider' => BillingProvider::Paystack,
                'reference' => 'admin_pay_'.$subscriptionCode,
                'status' => MerchantPaymentStatus::Success,
                'amount' => $plan->amount,
                'currency' => $plan->currency,
                'channel' => 'admin',
                'paid_at' => $periodStart,
                'gateway_response' => 'Assigned by admin',
                'provider_payload' => [
                    'source' => 'admin_assignment',
                    'assigned_by_admin_id' => $admin->id,
                ],
            ]);
        }

        return [
            'subscription' => $subscription->load(['plan', 'restaurant.organization']),
            'invoice' => $invoice?->load('plan'),
        ];
    }

    public function isRestaurantBillable(Restaurant $restaurant): bool
    {
        return Cache::remember(
            $this->performanceCache->billingEligibilityKey($restaurant->id),
            (int) config('performance.cache.ttls.billing_eligibility'),
            fn (): bool => MerchantSubscription::query()
                ->where('restaurant_id', $restaurant->id)
                ->whereIn('status', [
                    MerchantSubscriptionStatus::Active->value,
                    MerchantSubscriptionStatus::Trialing->value,
                ])
                ->where(function ($query): void {
                    $query->whereNull('current_period_end')
                        ->orWhere('current_period_end', '>=', now());
                })
                ->exists(),
        );
    }

    protected function recordTransaction(MerchantInvoice $invoice, array $data): MerchantPayment
    {
        $isUpgrade = (bool) ($invoice->metadata['is_upgrade'] ?? false);
        $paymentMethod = $this->upsertPaymentMethod($invoice->restaurant, $data);
        $status = $this->paymentStatusFrom((string) Arr::get($data, 'status', 'pending'));

        $subscription = null;
        if ($status === MerchantPaymentStatus::Success) {
            $subscription = $this->upsertSubscription($invoice, $data, $paymentMethod);
        }

        $payment = MerchantPayment::query()->updateOrCreate(
            ['reference' => (string) Arr::get($data, 'reference', $invoice->provider_reference)],
            [
                'restaurant_id' => $invoice->restaurant_id,
                'merchant_invoice_id' => $invoice->id,
                'merchant_subscription_id' => $subscription?->id,
                'merchant_payment_method_id' => $paymentMethod?->id,
                'provider' => BillingProvider::Paystack,
                'status' => $status,
                'amount' => (int) Arr::get($data, 'amount', $invoice->amount),
                'currency' => Arr::get($data, 'currency', $invoice->currency),
                'channel' => Arr::get($data, 'channel'),
                'paid_at' => $this->dateOrNull(Arr::get($data, 'paid_at')),
                'gateway_response' => Arr::get($data, 'gateway_response'),
                'provider_payload' => $data,
            ],
        );

        $paidAt = $status === MerchantPaymentStatus::Success
            ? ($this->dateOrNull(Arr::get($data, 'paid_at')) ?? now())
            : null;

        $invoice->update([
            'merchant_subscription_id' => $subscription?->id ?? $invoice->merchant_subscription_id,
            'receipt_number' => Arr::get($data, 'id', $invoice->receipt_number),
            'status' => $status === MerchantPaymentStatus::Success
                ? MerchantInvoiceStatus::Paid
                : MerchantInvoiceStatus::Failed,
            'paid_at' => $paidAt ?? $invoice->paid_at,
            'due_at' => $paidAt ? $paidAt->addMonth() : $invoice->due_at,
            'billing_period_start' => $paidAt ?? $invoice->billing_period_start,
            'billing_period_end' => $paidAt ? $paidAt->addMonth() : $invoice->billing_period_end,
            'metadata' => [
                ...($invoice->metadata ?? []),
                'gateway_response' => Arr::get($data, 'gateway_response'),
            ],
        ]);

        $this->performanceCache->invalidateBillingEligibility($invoice->restaurant_id);

        if ($status === MerchantPaymentStatus::Success) {
            $invoice->refresh()->loadMissing(['restaurant.organization', 'plan']);
            $billingEmail = $invoice->restaurant->billingEmail();

            if ($billingEmail) {
                Notification::route('mail', $billingEmail)
                    ->notify(new MerchantPaymentConfirmationNotification($invoice, $isUpgrade));
            }
        }

        return $payment->refresh();
    }

    protected function upsertPaymentMethod(Restaurant $restaurant, array $data): ?MerchantPaymentMethod
    {
        $authorization = Arr::get($data, 'authorization', []);

        if (! is_array($authorization) || ! Arr::get($authorization, 'authorization_code')) {
            return null;
        }

        MerchantPaymentMethod::query()
            ->where('restaurant_id', $restaurant->id)
            ->update(['is_default' => false]);

        return MerchantPaymentMethod::query()->updateOrCreate(
            ['authorization_code' => Arr::get($authorization, 'authorization_code')],
            [
                'restaurant_id' => $restaurant->id,
                'provider' => BillingProvider::Paystack,
                'provider_customer_code' => Arr::get($data, 'customer.customer_code'),
                'email' => Arr::get($data, 'customer.email'),
                'reusable' => (bool) Arr::get($authorization, 'reusable', false),
                'brand' => Arr::get($authorization, 'brand'),
                'card_type' => Arr::get($authorization, 'card_type'),
                'last4' => Arr::get($authorization, 'last4'),
                'exp_month' => Arr::get($authorization, 'exp_month'),
                'exp_year' => Arr::get($authorization, 'exp_year'),
                'bin' => Arr::get($authorization, 'bin'),
                'bank' => Arr::get($authorization, 'bank'),
                'signature' => Arr::get($authorization, 'signature'),
                'channel' => Arr::get($authorization, 'channel', Arr::get($data, 'channel')),
                'metadata' => $authorization,
                'is_default' => true,
            ],
        );
    }

    protected function upsertSubscription(
        MerchantInvoice $invoice,
        array $data,
        ?MerchantPaymentMethod $paymentMethod,
    ): MerchantSubscription {
        $subscriptionCode = Arr::get($data, 'subscription.subscription_code')
            ?? Arr::get($data, 'subscription_code')
            ?? 'local_'.$invoice->provider_reference;

        $nextPaymentAt = $this->dateOrNull(Arr::get($data, 'paid_at'))?->addMonth()
            ?? now()->addMonth();

        MerchantSubscription::query()
            ->where('restaurant_id', $invoice->restaurant_id)
            ->whereIn('status', [
                MerchantSubscriptionStatus::Active->value,
                MerchantSubscriptionStatus::Trialing->value,
            ])
            ->update([
                'status' => MerchantSubscriptionStatus::Canceled,
                'canceled_at' => now(),
            ]);

        return MerchantSubscription::query()->updateOrCreate(
            ['provider_subscription_code' => $subscriptionCode],
            [
                'restaurant_id' => $invoice->restaurant_id,
                'billing_plan_id' => $invoice->billing_plan_id,
                'provider' => BillingProvider::Paystack,
                'status' => MerchantSubscriptionStatus::Active,
                'provider_customer_code' => Arr::get($data, 'customer.customer_code'),
                'provider_email_token' => Arr::get($data, 'subscription.email_token'),
                'provider_authorization_code' => $paymentMethod?->authorization_code,
                'current_period_start' => $this->dateOrNull(Arr::get($data, 'paid_at')) ?? now(),
                'current_period_end' => $nextPaymentAt,
                'next_payment_at' => $nextPaymentAt,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'raw_provider_payload' => $data,
            ],
        );
    }

    protected function syncSubscriptionPayload(
        array $data,
        MerchantSubscriptionStatus $status,
        bool $cancelAtPeriodEnd = false,
    ): void {
        $subscriptionCode = Arr::get($data, 'subscription_code');

        if (! $subscriptionCode) {
            return;
        }

        $subscriptions = MerchantSubscription::query()
            ->where('provider_subscription_code', $subscriptionCode)
            ->get(['id', 'restaurant_id']);

        MerchantSubscription::query()
            ->whereKey($subscriptions->pluck('id'))
            ->update([
                'status' => $status,
                'cancel_at_period_end' => $cancelAtPeriodEnd,
                'next_payment_at' => $this->dateOrNull(Arr::get($data, 'next_payment_date')),
                'canceled_at' => $status === MerchantSubscriptionStatus::Canceled ? now() : null,
                'raw_provider_payload' => $data,
            ]);

        $subscriptions->each(fn (MerchantSubscription $subscription) => $this->performanceCache
            ->invalidateBillingEligibility($subscription->restaurant_id));
    }

    protected function paymentStatusFrom(string $status): MerchantPaymentStatus
    {
        return match ($status) {
            'success' => MerchantPaymentStatus::Success,
            'failed' => MerchantPaymentStatus::Failed,
            'abandoned' => MerchantPaymentStatus::Abandoned,
            default => MerchantPaymentStatus::Pending,
        };
    }

    protected function cancelActiveSubscriptions(Restaurant $restaurant): void
    {
        MerchantSubscription::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', [
                MerchantSubscriptionStatus::Active->value,
                MerchantSubscriptionStatus::Trialing->value,
                MerchantSubscriptionStatus::PastDue->value,
            ])
            ->update([
                'status' => MerchantSubscriptionStatus::Canceled,
                'canceled_at' => now(),
            ]);
    }

    protected function defaultPeriodEnd(BillingPlan $plan, CarbonImmutable $start): CarbonImmutable
    {
        return match ($plan->interval) {
            'yearly', 'annual' => $start->addYear(),
            default => $start->addMonth(),
        };
    }

    protected function generateInvoiceNumber(): string
    {
        do {
            $number = 'MT-INV-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (MerchantInvoice::query()->where('invoice_number', $number)->exists());

        return $number;
    }

    protected function dateOrNull(mixed $value): ?CarbonImmutable
    {
        if (! $value) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
