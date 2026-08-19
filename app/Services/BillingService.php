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
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use App\Notifications\MerchantPaymentConfirmationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
    public function initializeCheckout(Organization|Restaurant $owner, BillingPlan $plan, bool $isUpgrade = false, ?string $requesterEmail = null): array
    {
        // Without a plan code Paystack takes a one-off payment instead of creating a subscription,
        // so nothing ever renews and the merchant silently lapses at the end of the period.
        if (! $plan->provider_plan_code) {
            Log::warning('Billing plan has no Paystack plan code; checkout will not create a renewable subscription.', [
                'billing_plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                ...$this->ownerAttributes($owner),
            ]);
        }

        $invoice = MerchantInvoice::query()->create([
            ...$this->ownerAttributes($owner),
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

        $providerResponse = $this->provider->initializeSubscriptionCheckout($owner, $plan, $invoice, $requesterEmail);

        return [
            'reference' => $invoice->provider_reference,
            'invoice' => $invoice,
            'provider_response' => $providerResponse,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyCheckout(Organization|Restaurant $owner, string $reference): array
    {
        $response = $this->provider->verifyTransaction($reference);
        $data = Arr::get($response, 'data', []);

        $invoice = $this->scopeToOwner(MerchantInvoice::query(), $owner)
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
            $this->recordSubscriptionCharge($data);
        }

        // Paystack bills renewals through invoices, and the renewal's charge.success carries no
        // subscription code — invoice.update is the only event that ties the renewal payment back
        // to the subscription, so without it a subscription never moves past its first period.
        if ($event === 'invoice.update' && Arr::get($data, 'status') === 'success') {
            $this->recordSubscriptionCharge($this->transactionFromInvoicePayload($data));
        }

        // A declined renewal leaves the subscription past due rather than silently running out its
        // period and being expired by the scheduler with no record of why.
        if ($event === 'invoice.payment_failed' || ($event === 'invoice.update' && Arr::get($data, 'status') === 'failed')) {
            $this->markRenewalFailed($data);
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
     * Manually assign a billing subscription to a business or a single restaurant (admin override,
     * no Paystack checkout). Assigning to a business covers every restaurant it owns, up to the
     * plan's restaurant allowance.
     *
     * @param  array{status?: string, current_period_end?: string|null, notes?: string|null, record_invoice?: bool}  $attributes
     * @return array{subscription: MerchantSubscription, invoice: ?MerchantInvoice}
     */
    public function assignAdminSubscription(
        Organization|Restaurant $owner,
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

        $this->cancelActiveSubscriptions($owner);

        $subscriptionCode = 'admin_'.strtolower((string) Str::ulid());

        $subscription = MerchantSubscription::query()->create([
            ...$this->ownerAttributes($owner),
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
                ...$this->ownerAttributes($owner),
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
                ...$this->ownerAttributes($owner),
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

        $this->invalidateEligibilityFor($subscription);

        return [
            'subscription' => $subscription->load(['plan', 'organization', 'restaurant.organization']),
            'invoice' => $invoice?->load('plan'),
        ];
    }

    /**
     * A restaurant is billable on its own subscription or, failing that, on the one its business
     * holds — see Restaurant::effectiveBillingSubscription().
     */
    public function isRestaurantBillable(Restaurant $restaurant): bool
    {
        return Cache::remember(
            $this->performanceCache->billingEligibilityKey($restaurant->id),
            (int) config('performance.cache.ttls.billing_eligibility'),
            fn (): bool => $restaurant->effectiveBillingSubscription() !== null,
        );
    }

    public function isBusinessBillable(Organization $organization): bool
    {
        return $organization->activeBillingSubscription()->exists();
    }

    /**
     * Whether the plan's restaurant allowance covers everything the business already owns. The
     * first two tiers cover a single restaurant; only Premium is unlimited.
     */
    public function planCoversBusiness(Organization $organization, BillingPlan $plan): bool
    {
        return $plan->allowsRestaurantCount($organization->restaurants()->count());
    }

    /**
     * Repair a subscription from the provider's own record of it. Webhooks are the primary path;
     * this is the fallback for the ones that never arrived or were dropped.
     */
    public function syncSubscriptionFromProvider(MerchantSubscription $subscription): void
    {
        $code = $subscription->provider_subscription_code;

        // admin_ and local_ codes only exist here — the provider has nothing to compare against.
        if (! $code || ! str_starts_with($code, 'SUB_')) {
            return;
        }

        $data = Arr::get($this->provider->syncSubscription($code), 'data', []);

        if (! is_array($data) || ! Arr::get($data, 'subscription_code')) {
            return;
        }

        [$status, $cancelAtPeriodEnd] = match ((string) Arr::get($data, 'status')) {
            'active' => [MerchantSubscriptionStatus::Active, false],
            'non-renewing' => [MerchantSubscriptionStatus::Active, true],
            'attention' => [MerchantSubscriptionStatus::PastDue, false],
            default => [MerchantSubscriptionStatus::Canceled, false],
        };

        $this->syncSubscriptionPayload($data, $status, $cancelAtPeriodEnd);
    }

    /**
     * Record a provider transaction against its invoice, creating one first when the charge is a
     * subscription renewal the app never initiated a checkout for.
     *
     * @param  array<string, mixed>  $data
     */
    protected function recordSubscriptionCharge(array $data): void
    {
        $reference = Arr::get($data, 'reference');

        if (! $reference) {
            return;
        }

        $invoice = MerchantInvoice::query()
            ->where('provider_reference', $reference)
            ->first();

        if (! $invoice) {
            $subscriptionCode = Arr::get($data, 'subscription.subscription_code')
                ?? Arr::get($data, 'subscription_code');

            $subscription = $subscriptionCode
                ? MerchantSubscription::query()
                    ->with('plan')
                    ->where('provider_subscription_code', $subscriptionCode)
                    ->first()
                : null;

            if (! $subscription) {
                return;
            }

            $invoice = MerchantInvoice::query()->create([
                'organization_id' => $subscription->organization_id,
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

        $this->recordTransaction($invoice, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function markRenewalFailed(array $data): void
    {
        $subscriptionCode = Arr::get($data, 'subscription.subscription_code')
            ?? Arr::get($data, 'subscription_code');

        if (! $subscriptionCode) {
            return;
        }

        $subscriptions = MerchantSubscription::query()
            ->where('provider_subscription_code', $subscriptionCode)
            ->whereIn('status', [
                MerchantSubscriptionStatus::Active->value,
                MerchantSubscriptionStatus::Trialing->value,
            ])
            ->get(['id', 'organization_id', 'restaurant_id']);

        MerchantSubscription::query()
            ->whereKey($subscriptions->pluck('id'))
            ->update([
                'status' => MerchantSubscriptionStatus::PastDue,
                'raw_provider_payload' => $data,
            ]);

        $subscriptions->each(fn (MerchantSubscription $subscription) => $this->invalidateEligibilityFor($subscription));
    }

    /**
     * Flatten a Paystack subscription invoice payload into the transaction shape recordTransaction expects.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function transactionFromInvoicePayload(array $data): array
    {
        $transaction = is_array(Arr::get($data, 'transaction')) ? $data['transaction'] : [];

        return [
            ...$transaction,
            'reference' => Arr::get($transaction, 'reference') ?: Arr::get($data, 'invoice_code'),
            'status' => Arr::get($transaction, 'status') ?: Arr::get($data, 'status'),
            'amount' => Arr::get($data, 'amount') ?? Arr::get($transaction, 'amount'),
            'currency' => Arr::get($data, 'currency') ?? Arr::get($transaction, 'currency'),
            'paid_at' => Arr::get($data, 'paid_at') ?: Arr::get($transaction, 'paid_at'),
            'customer' => Arr::get($data, 'customer'),
            'authorization' => Arr::get($data, 'authorization'),
            'subscription' => Arr::get($data, 'subscription'),
        ];
    }

    protected function recordTransaction(MerchantInvoice $invoice, array $data): MerchantPayment
    {
        $isUpgrade = (bool) ($invoice->metadata['is_upgrade'] ?? false);
        $paymentMethod = $this->upsertPaymentMethod($invoice, $data);
        $status = $this->paymentStatusFrom((string) Arr::get($data, 'status', 'pending'));

        $subscription = null;
        if ($status === MerchantPaymentStatus::Success) {
            $subscription = $this->upsertSubscription($invoice, $data, $paymentMethod);
        }

        $payment = MerchantPayment::query()->updateOrCreate(
            ['reference' => (string) Arr::get($data, 'reference', $invoice->provider_reference)],
            [
                'organization_id' => $invoice->organization_id,
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
            'status' => $this->invoiceStatusFrom($status),
            'paid_at' => $paidAt ?? $invoice->paid_at,
            'due_at' => $paidAt ? $paidAt->addMonth() : $invoice->due_at,
            'billing_period_start' => $paidAt ?? $invoice->billing_period_start,
            'billing_period_end' => $paidAt ? $paidAt->addMonth() : $invoice->billing_period_end,
            'metadata' => [
                ...($invoice->metadata ?? []),
                'gateway_response' => Arr::get($data, 'gateway_response'),
            ],
        ]);

        $this->invalidateEligibilityFor($invoice);

        if ($status === MerchantPaymentStatus::Success) {
            $invoice->refresh()->loadMissing(['organization', 'restaurant.organization', 'plan']);
            $billingEmail = $invoice->restaurant?->billingEmail() ?? $invoice->organization?->billingEmail();

            if ($billingEmail) {
                Notification::route('mail', $billingEmail)
                    ->notify(new MerchantPaymentConfirmationNotification($invoice, $isUpgrade));
            }
        }

        return $payment->refresh();
    }

    protected function upsertPaymentMethod(MerchantInvoice $invoice, array $data): ?MerchantPaymentMethod
    {
        $authorization = Arr::get($data, 'authorization', []);

        if (! is_array($authorization) || ! Arr::get($authorization, 'authorization_code')) {
            return null;
        }

        $ownerAttributes = [
            'organization_id' => $invoice->organization_id,
            'restaurant_id' => $invoice->restaurant_id,
        ];

        $this->scopeToOwnerColumns(MerchantPaymentMethod::query(), $ownerAttributes)
            ->update(['is_default' => false]);

        return MerchantPaymentMethod::query()->updateOrCreate(
            ['authorization_code' => Arr::get($authorization, 'authorization_code')],
            [
                ...$ownerAttributes,
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

        // Paystack knows when it will charge next; only guess when it doesn't say.
        $nextPaymentAt = $this->dateOrNull(Arr::get($data, 'subscription.next_payment_date'))
            ?? $this->dateOrNull(Arr::get($data, 'paid_at'))?->addMonth()
            ?? now()->addMonth();

        $ownerAttributes = [
            'organization_id' => $invoice->organization_id,
            'restaurant_id' => $invoice->restaurant_id,
        ];

        $this->scopeToOwnerColumns(MerchantSubscription::query(), $ownerAttributes)
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
                ...$ownerAttributes,
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
            ->get(['id', 'organization_id', 'restaurant_id']);

        $nextPaymentAt = $this->dateOrNull(Arr::get($data, 'next_payment_date'));

        $attributes = [
            'status' => $status,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
            'next_payment_at' => $nextPaymentAt,
            'canceled_at' => $status === MerchantSubscriptionStatus::Canceled ? now() : null,
            'raw_provider_payload' => $data,
        ];

        // Eligibility and expiry both read current_period_end, so it has to track the date Paystack
        // will next charge on — otherwise a live subscription still gets expired by the scheduler.
        if ($nextPaymentAt && $status === MerchantSubscriptionStatus::Active) {
            $attributes['current_period_end'] = $nextPaymentAt;
        }

        MerchantSubscription::query()
            ->whereKey($subscriptions->pluck('id'))
            ->update($attributes);

        $subscriptions->each(fn (MerchantSubscription $subscription) => $this->invalidateEligibilityFor($subscription));
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

    /**
     * A charge that has not resolved yet leaves the invoice pending — only a decline fails it,
     * otherwise verifying a checkout before the guest pays would mark the invoice failed.
     */
    protected function invoiceStatusFrom(MerchantPaymentStatus $status): MerchantInvoiceStatus
    {
        return match ($status) {
            MerchantPaymentStatus::Success => MerchantInvoiceStatus::Paid,
            MerchantPaymentStatus::Failed => MerchantInvoiceStatus::Failed,
            MerchantPaymentStatus::Abandoned => MerchantInvoiceStatus::Canceled,
            MerchantPaymentStatus::Pending => MerchantInvoiceStatus::Pending,
        };
    }

    protected function cancelActiveSubscriptions(Organization|Restaurant $owner): void
    {
        $this->scopeToOwner(MerchantSubscription::query(), $owner)
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

    /**
     * The owning columns for a billing record. A business owns its subscription outright
     * (restaurant_id null); a restaurant-owned record still records the business it belongs to so
     * billing can be reported per business either way.
     *
     * @return array{organization_id: ?int, restaurant_id: ?int}
     */
    protected function ownerAttributes(Organization|Restaurant $owner): array
    {
        return $owner instanceof Restaurant
            ? ['organization_id' => $owner->organization_id, 'restaurant_id' => $owner->id]
            : ['organization_id' => $owner->id, 'restaurant_id' => null];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scopeToOwner(Builder $query, Organization|Restaurant $owner): Builder
    {
        return $this->scopeToOwnerColumns($query, $this->ownerAttributes($owner));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array{organization_id: ?int, restaurant_id: ?int}  $ownerAttributes
     * @return Builder<TModel>
     */
    protected function scopeToOwnerColumns(Builder $query, array $ownerAttributes): Builder
    {
        return $query
            ->where('organization_id', $ownerAttributes['organization_id'])
            ->when(
                $ownerAttributes['restaurant_id'] === null,
                fn (Builder $ownerQuery): Builder => $ownerQuery->whereNull('restaurant_id'),
                fn (Builder $ownerQuery): Builder => $ownerQuery->where('restaurant_id', $ownerAttributes['restaurant_id']),
            );
    }

    /**
     * A business-level record changes what every restaurant under that business is entitled to, so
     * the eligibility cache has to be cleared for all of them, not just one.
     */
    protected function invalidateEligibilityFor(MerchantInvoice|MerchantSubscription $record): void
    {
        if ($record->restaurant_id !== null) {
            $this->performanceCache->invalidateBillingEligibility($record->restaurant_id);

            return;
        }

        if ($record->organization_id !== null) {
            $this->performanceCache->invalidateBusinessBillingEligibility($record->organization_id);
        }
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
