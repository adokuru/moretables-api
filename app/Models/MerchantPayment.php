<?php

namespace App\Models;

use App\BillingProvider;
use App\MerchantPaymentStatus;
use Database\Factories\MerchantPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantPayment extends Model
{
    /** @use HasFactory<MerchantPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'restaurant_id',
        'merchant_invoice_id',
        'merchant_subscription_id',
        'merchant_payment_method_id',
        'provider',
        'reference',
        'status',
        'amount',
        'currency',
        'channel',
        'paid_at',
        'gateway_response',
        'provider_payload',
    ];

    protected function casts(): array
    {
        return [
            'provider' => BillingProvider::class,
            'status' => MerchantPaymentStatus::class,
            'amount' => 'integer',
            'paid_at' => 'datetime',
            'provider_payload' => 'array',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(MerchantInvoice::class, 'merchant_invoice_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MerchantSubscription::class, 'merchant_subscription_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(MerchantPaymentMethod::class, 'merchant_payment_method_id');
    }
}
