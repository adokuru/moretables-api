<?php

namespace App\Models;

use App\BillingProvider;
use App\MerchantInvoiceStatus;
use Database\Factories\MerchantInvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantInvoice extends Model
{
    /** @use HasFactory<MerchantInvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'billing_plan_id',
        'merchant_subscription_id',
        'invoice_number',
        'provider',
        'provider_reference',
        'receipt_number',
        'amount',
        'currency',
        'status',
        'billing_period_start',
        'billing_period_end',
        'due_at',
        'paid_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'provider' => BillingProvider::class,
            'amount' => 'integer',
            'status' => MerchantInvoiceStatus::class,
            'billing_period_start' => 'datetime',
            'billing_period_end' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'metadata' => 'array',
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

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MerchantSubscription::class, 'merchant_subscription_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MerchantPayment::class);
    }
}
