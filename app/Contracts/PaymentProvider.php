<?php

namespace App\Contracts;

use App\Models\BillingPlan;
use App\Models\MerchantInvoice;
use App\Models\Organization;
use App\Models\Restaurant;

interface PaymentProvider
{
    /**
     * @return array<string, mixed>
     */
    public function initializeSubscriptionCheckout(Organization|Restaurant $owner, BillingPlan $plan, MerchantInvoice $invoice, ?string $fallbackEmail = null): array;

    /**
     * @return array<string, mixed>
     */
    public function verifyTransaction(string $reference): array;

    /**
     * Initialize a card authorization (verification) transaction so the card can be charged later.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function initializeCardAuthorization(string $email, int $amount, string $reference, string $currency, array $metadata = []): array;

    /**
     * Charge a previously saved card authorization.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function chargeAuthorization(string $authorizationCode, string $email, int $amount, string $reference, string $currency, array $metadata = []): array;

    /**
     * Refund a transaction (full refund when amount is null).
     *
     * @return array<string, mixed>
     */
    public function refundTransaction(string $reference, ?int $amount = null): array;

    /**
     * @return array<string, mixed>
     */
    public function syncSubscription(string $subscriptionCode): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handleWebhook(array $payload): array;

    public function validateWebhookSignature(string $payload, ?string $signature): bool;
}
