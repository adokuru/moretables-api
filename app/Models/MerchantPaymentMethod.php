<?php

namespace App\Models;

use App\BillingProvider;
use Database\Factories\MerchantPaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantPaymentMethod extends Model
{
    /** @use HasFactory<MerchantPaymentMethodFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'restaurant_id',
        'provider',
        'provider_customer_code',
        'authorization_code',
        'email',
        'reusable',
        'brand',
        'card_type',
        'last4',
        'exp_month',
        'exp_year',
        'bin',
        'bank',
        'signature',
        'channel',
        'metadata',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'provider' => BillingProvider::class,
            'reusable' => 'boolean',
            'metadata' => 'array',
            'is_default' => 'boolean',
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

    public function payments(): HasMany
    {
        return $this->hasMany(MerchantPayment::class);
    }
}
