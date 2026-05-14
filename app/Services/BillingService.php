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
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class BillingService
{
    public function __construct(protected PaymentProvider $provider) {}

    /**
     * @return array<string, mixed>
     */
    public function initializeCheckout(Restaurant $restaurant, BillingPlan $plan): array
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
        ]);

        $response = $this->provider->initializeSubscriptionCheckout($restaurant->loadMissing('organization'), $plan, $invoice);

        return [
            'authorization_url' => Arr::get($response, 'data.authorization_url'),
            'access_code' => Arr::get($response, 'data.access_code'),
            'reference' => $invoice->provider_reference,
            'invoice' => $invoice,
            'provider_response' => $response,
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

            if ($invoice) {
                $this->recordTransaction($invoice, $data);
            }
        }

        if (in_array($event, ['subscription.create', 'subscription.enable'], true)) {
            $this->syncSubscriptionPayload($data, MerchantSubscriptionStatus::Active);
        }

        if (in_array($event, ['subscription.disable', 'subscription.not_renew'], true)) {
            $this->syncSubscriptionPayload($data, MerchantSubscriptionStatus::Canceled);
        }
    }

    public function isRestaurantBillable(Restaurant $restaurant): bool
    {
        return MerchantSubscription::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', [
                MerchantSubscriptionStatus::Active->value,
                MerchantSubscriptionStatus::Trialing->value,
            ])
            ->where(function ($query): void {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>=', now());
            })
            ->exists();
    }

    protected function recordTransaction(MerchantInvoice $invoice, array $data): MerchantPayment
    {
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

        $invoice->update([
            'merchant_subscription_id' => $subscription?->id ?? $invoice->merchant_subscription_id,
            'receipt_number' => Arr::get($data, 'id', $invoice->receipt_number),
            'status' => $status === MerchantPaymentStatus::Success
                ? MerchantInvoiceStatus::Paid
                : MerchantInvoiceStatus::Failed,
            'paid_at' => $status === MerchantPaymentStatus::Success ? ($this->dateOrNull(Arr::get($data, 'paid_at')) ?? now()) : $invoice->paid_at,
            'metadata' => [
                ...($invoice->metadata ?? []),
                'gateway_response' => Arr::get($data, 'gateway_response'),
            ],
        ]);

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

        $nextPaymentAt = $this->dateOrNull(Arr::get($data, 'subscription.next_payment_date'))
            ?? $this->dateOrNull(Arr::get($data, 'paid_at'))?->addMonth()
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

    protected function syncSubscriptionPayload(array $data, MerchantSubscriptionStatus $status): void
    {
        $subscriptionCode = Arr::get($data, 'subscription_code');

        if (! $subscriptionCode) {
            return;
        }

        MerchantSubscription::query()
            ->where('provider_subscription_code', $subscriptionCode)
            ->update([
                'status' => $status,
                'next_payment_at' => $this->dateOrNull(Arr::get($data, 'next_payment_date')),
                'canceled_at' => $status === MerchantSubscriptionStatus::Canceled ? now() : null,
                'raw_provider_payload' => $data,
            ]);
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
