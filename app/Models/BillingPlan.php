<?php

namespace App\Models;

use App\BillingPlanSlug;
use App\BillingProvider;
use Database\Factories\BillingPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingPlan extends Model
{
    /** @use HasFactory<BillingPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'amount',
        'currency',
        'interval',
        'max_restaurants',
        'provider',
        'provider_plan_code',
        'features',
        'metadata',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'slug' => BillingPlanSlug::class,
            'amount' => 'integer',
            'max_restaurants' => 'integer',
            'provider' => BillingProvider::class,
            'features' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * A null max_restaurants means the plan covers every restaurant the business owns.
     */
    public function allowsUnlimitedRestaurants(): bool
    {
        return $this->max_restaurants === null;
    }

    public function allowsRestaurantCount(int $count): bool
    {
        return $this->allowsUnlimitedRestaurants() || $count <= $this->max_restaurants;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MerchantSubscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(MerchantInvoice::class);
    }
}
