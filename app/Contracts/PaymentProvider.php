<?php

namespace App\Contracts;

use App\Models\BillingPlan;
use App\Models\MerchantInvoice;
use App\Models\Restaurant;

interface PaymentProvider
{
    /**
     * @return array<string, mixed>
     */
    public function initializeSubscriptionCheckout(Restaurant $restaurant, BillingPlan $plan, MerchantInvoice $invoice): array;

    /**
     * @return array<string, mixed>
     */
    public function verifyTransaction(string $reference): array;

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
