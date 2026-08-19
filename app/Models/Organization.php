<?php

namespace App\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'primary_contact_name',
        'primary_contact_email',
        'primary_contact_phone',
        'business_phone',
        'business_email',
        'website',
        'billing_email',
        'tax_id',
        'registration_number',
        'city',
        'state',
        'country',
        'status',
        'planned_restaurants_count',
    ];

    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }

    /**
     * Subscriptions the business itself holds. Restaurant-level subscriptions carry this
     * organization too, so they are excluded here — only a business-level subscription trickles
     * down to the restaurants.
     */
    public function billingSubscriptions(): HasMany
    {
        return $this->hasMany(MerchantSubscription::class)->businessLevel();
    }

    public function latestBillingSubscription(): HasOne
    {
        return $this->hasOne(MerchantSubscription::class)
            ->businessLevel()
            ->latestOfMany();
    }

    public function activeBillingSubscription(): HasOne
    {
        return $this->hasOne(MerchantSubscription::class)
            ->businessLevel()
            ->active()
            ->latestOfMany();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(MerchantInvoice::class)->whereNull('restaurant_id');
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(MerchantPaymentMethod::class)->whereNull('restaurant_id');
    }

    public function defaultPaymentMethod(): HasOne
    {
        return $this->hasOne(MerchantPaymentMethod::class)
            ->whereNull('restaurant_id')
            ->where('is_default', true)
            ->latestOfMany();
    }

    /**
     * The best-known billing contact email for this business, skipping blank (not just null)
     * values so a stray empty string doesn't block the fallback chain.
     */
    public function billingEmail(): ?string
    {
        foreach ([$this->billing_email, $this->business_email, $this->primary_contact_email] as $candidate) {
            if (filled($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')
            ->withPivot(['role_id', 'restaurant_id', 'assigned_by', 'scope_type'])
            ->withTimestamps();
    }
}
