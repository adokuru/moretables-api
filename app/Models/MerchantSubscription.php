<?php

namespace App\Models;

use App\BillingProvider;
use App\MerchantSubscriptionStatus;
use Database\Factories\MerchantSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantSubscription extends Model
{
    /** @use HasFactory<MerchantSubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'billing_plan_id',
        'provider',
        'status',
        'provider_customer_code',
        'provider_subscription_code',
        'provider_email_token',
        'provider_authorization_code',
        'current_period_start',
        'current_period_end',
        'next_payment_at',
        'cancel_at_period_end',
        'canceled_at',
        'metadata',
        'raw_provider_payload',
    ];

    protected function casts(): array
    {
        return [
            'provider' => BillingProvider::class,
            'status' => MerchantSubscriptionStatus::class,
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'next_payment_at' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'metadata' => 'array',
            'raw_provider_payload' => 'array',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BillingPlan::class, 'billing_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(MerchantInvoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MerchantPayment::class);
    }

    public function isBillable(): bool
    {
        return in_array($this->status, [
            MerchantSubscriptionStatus::Active,
            MerchantSubscriptionStatus::Trialing,
        ], true);
    }
}
