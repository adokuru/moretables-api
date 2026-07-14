<?php

namespace App\Services\Payments;

use App\Contracts\PaymentProvider;
use App\Models\BillingPlan;
use App\Models\MerchantInvoice;
use App\Models\Restaurant;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class PaystackPaymentProvider implements PaymentProvider
{
    public function initializeSubscriptionCheckout(Restaurant $restaurant, BillingPlan $plan, MerchantInvoice $invoice): array
    {
        $payload = [
            'email' => $this->billingEmailFor($restaurant),
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'reference' => $invoice->provider_reference,
            'callback_url' => config('billing.providers.paystack.callback_url'),
            'metadata' => [
                'restaurant_id' => $restaurant->id,
                'billing_plan_id' => $plan->id,
                'merchant_invoice_id' => $invoice->id,
            ],
        ];

        if ($plan->provider_plan_code) {
            $payload['plan'] = $plan->provider_plan_code;
        }

        return $this->client()
            ->post('/transaction/initialize', array_filter($payload, fn ($value): bool => $value !== null))
            ->throw()
            ->json();
    }

    public function verifyTransaction(string $reference): array
    {
        return $this->client()
            ->get('/transaction/verify/'.urlencode($reference))
            ->throw()
            ->json();
    }

    public function initializeCardAuthorization(string $email, int $amount, string $reference, string $currency, array $metadata = []): array
    {
        return $this->client()
            ->post('/transaction/initialize', [
                'email' => $email,
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $reference,
                'callback_url' => config('billing.card_hold.callback_url'),
                'metadata' => $metadata,
            ])
            ->throw()
            ->json();
    }

    public function chargeAuthorization(string $authorizationCode, string $email, int $amount, string $reference, string $currency, array $metadata = []): array
    {
        return $this->client()
            ->post('/transaction/charge_authorization', [
                'authorization_code' => $authorizationCode,
                'email' => $email,
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $reference,
                'metadata' => $metadata,
            ])
            ->throw()
            ->json();
    }

    public function refundTransaction(string $reference, ?int $amount = null): array
    {
        return $this->client()
            ->post('/refund', array_filter([
                'transaction' => $reference,
                'amount' => $amount,
            ], fn ($value): bool => $value !== null))
            ->throw()
            ->json();
    }

    public function syncSubscription(string $subscriptionCode): array
    {
        return $this->client()
            ->get('/subscription/'.urlencode($subscriptionCode))
            ->throw()
            ->json();
    }

    public function handleWebhook(array $payload): array
    {
        return [
            'event' => $payload['event'] ?? null,
            'data' => $payload['data'] ?? [],
        ];
    }

    public function validateWebhookSignature(string $payload, ?string $signature): bool
    {
        if (! $signature) {
            return false;
        }

        $secret = (string) config('billing.providers.paystack.webhook_secret');

        if ($secret === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $payload, $secret), $signature);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('billing.providers.paystack.base_url'))
            ->withToken((string) config('billing.providers.paystack.secret_key'))
            ->acceptJson()
            ->asJson();
    }

    protected function billingEmailFor(Restaurant $restaurant): string
    {
        return $restaurant->organization?->billing_email
            ?? $restaurant->organization?->business_email
            ?? $restaurant->email
            ?? $restaurant->organization?->primary_contact_email
            ?? 'billing@moretables.local';
    }
}
