<?php

namespace App\Models;

use App\RestaurantStatus;
use Database\Factories\RestaurantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Restaurant extends Model implements HasMedia
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    use InteractsWithMedia;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'status',
        'is_featured',
        'rewards_enabled',
        'reservation_reward_points',
        'email',
        'phone',
        'city',
        'state',
        'country',
        'timezone',
        'address_line_1',
        'address_line_2',
        'latitude',
        'longitude',
        'description',
        'internal_notes',
        'website',
        'instagram_handle',
        'average_price_range',
        'dining_style',
        'dress_code',
        'total_seating_capacity',
        'number_of_tables',
        'menu_source',
        'menu_link',
        'executive_chef',
        'dietary_options',
        'beverage_options',
        'amenities',
        'smoking_policy',
        'delivery_options',
        'safety_policies',
        'parking_info',
        'closest_landmark',
        'private_events_info',
        'catering_info',
        'payment_options',
        'accessibility_features',
        'onboarding_progress',
        'onboarding_current_step',
        'onboarding_last_step',
        'is_profile_published',
        'contact_email_verified_at',
        'contact_phone_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RestaurantStatus::class,
            'is_featured' => 'boolean',
            'rewards_enabled' => 'boolean',
            'reservation_reward_points' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'total_seating_capacity' => 'integer',
            'number_of_tables' => 'integer',
            'dietary_options' => 'array',
            'beverage_options' => 'array',
            'amenities' => 'array',
            'smoking_policy' => 'array',
            'safety_policies' => 'array',
            'payment_options' => 'array',
            'accessibility_features' => 'array',
            'onboarding_progress' => 'array',
            'is_profile_published' => 'boolean',
            'contact_email_verified_at' => 'datetime',
            'contact_phone_verified_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function cuisines(): BelongsToMany
    {
        return $this->belongsToMany(CuisineOption::class, 'cuisine_option_restaurant')
            ->withPivot('is_primary')
            ->withTimestamps()
            ->orderByDesc('cuisine_option_restaurant.is_primary')
            ->orderBy('cuisine_options.name');
    }

    public function hours(): HasMany
    {
        return $this->hasMany(RestaurantHour::class);
    }

    public function policy(): HasOne
    {
        return $this->hasOne(RestaurantPolicy::class);
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(RestaurantMenuItem::class)->orderBy('sort_order');
    }

    public function diningAreas(): HasMany
    {
        return $this->hasMany(DiningArea::class);
    }

    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function guestContacts(): HasMany
    {
        return $this->hasMany(GuestContact::class)->orderBy('first_name')->orderBy('last_name');
    }

    public function views(): HasMany
    {
        return $this->hasMany(RestaurantView::class);
    }

    public function savedEntries(): HasMany
    {
        return $this->hasMany(SavedRestaurant::class);
    }

    public function listItems(): HasMany
    {
        return $this->hasMany(UserRestaurantListItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(RestaurantReview::class);
    }

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
    }

    public function guestCommunicationSetting(): HasOne
    {
        return $this->hasOne(GuestCommunicationSetting::class);
    }

    public function billingSubscriptions(): HasMany
    {
        return $this->hasMany(MerchantSubscription::class);
    }

    public function activeBillingSubscription(): HasOne
    {
        return $this->hasOne(MerchantSubscription::class)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($q): void {
                $q->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>=', now());
            })
            ->latestOfMany();
    }

    public function paymentMethods(): HasMany
    {
        return $this->hasMany(MerchantPaymentMethod::class);
    }

    public function defaultPaymentMethod(): HasOne
    {
        return $this->hasOne(MerchantPaymentMethod::class)
            ->where('is_default', true)
            ->latestOfMany();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(MerchantInvoice::class);
    }

    public function socialHandles(): HasMany
    {
        return $this->hasMany(RestaurantSocialHandle::class)->orderBy('platform');
    }

    public function mealTypes(): HasMany
    {
        return $this->hasMany(RestaurantMealType::class)->orderBy('sort_order');
    }

    public function mealSchedules(): HasMany
    {
        return $this->hasMany(RestaurantMealSchedule::class);
    }

    public function galleryCategories(): HasMany
    {
        return $this->hasMany(RestaurantGalleryCategory::class)->orderBy('sort_order')->orderBy('name');
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(RestaurantInternalNote::class)->latest();
    }

    public function accessConfigs(): HasMany
    {
        return $this->hasMany(RestaurantAccessConfig::class);
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('featured')->singleFile();
        $this->addMediaCollection('gallery');
        $this->addMediaCollection('menu_documents')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 320, 240)
            ->performOnCollections('featured', 'gallery')
            ->nonQueued();

        $this->addMediaConversion('card')
            ->fit(Fit::Crop, 900, 640)
            ->performOnCollections('featured', 'gallery')
            ->nonQueued();
    }
}
