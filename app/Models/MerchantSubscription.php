<?php

namespace App\Models;

use App\BillingProvider;
use App\MerchantSubscriptionStatus;
use Database\Factories\MerchantSubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantSubscription extends Model
{
    /** @use HasFactory<MerchantSubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
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

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * A business-level subscription is owned by the organization and inherited by every restaurant
     * it owns, up to the plan's restaurant allowance. Restaurant-level subscriptions predate that
     * model and stay bound to the single restaurant that bought them.
     */
    public function isBusinessLevel(): bool
    {
        return $this->restaurant_id === null && $this->organization_id !== null;
    }

    /**
     * Subscriptions that entitle their owner to service right now — billable status and a period
     * that has not run out.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereIn($query->qualifyColumn('status'), [
                MerchantSubscriptionStatus::Active->value,
                MerchantSubscriptionStatus::Trialing->value,
            ])
            ->where(function (Builder $periodQuery): void {
                $periodQuery
                    ->whereNull($periodQuery->qualifyColumn('current_period_end'))
                    ->orWhere($periodQuery->qualifyColumn('current_period_end'), '>=', now());
            });
    }

    public function scopeBusinessLevel(Builder $query): Builder
    {
        return $query
            ->whereNull($query->qualifyColumn('restaurant_id'))
            ->whereNotNull($query->qualifyColumn('organization_id'));
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
