<?php

namespace App\Models;

use App\RestaurantStatus;
use Database\Factories\RestaurantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'website',
        'instagram_handle',
        'average_price_range',
        'dining_style',
        'dress_code',
        'total_seating_capacity',
        'number_of_tables',
        'menu_source',
        'menu_link',
        'payment_options',
        'accessibility_features',
    ];

    protected function casts(): array
    {
        return [
            'status' => RestaurantStatus::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'total_seating_capacity' => 'integer',
            'number_of_tables' => 'integer',
            'payment_options' => 'array',
            'accessibility_features' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function cuisines(): HasMany
    {
        return $this->hasMany(RestaurantCuisine::class);
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

    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
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
