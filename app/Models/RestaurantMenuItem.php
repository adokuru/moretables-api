<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class RestaurantMenuItem extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\RestaurantMenuItemFactory> */
    use HasFactory;

    use InteractsWithMedia;

    protected $fillable = [
        'restaurant_id',
        'section_name',
        'item_name',
        'description',
        'price',
        'currency',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('featured')->singleFile();
        $this->addMediaCollection('gallery');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 240, 240)
            ->nonQueued();

        $this->addMediaConversion('card')
            ->fit(Fit::Crop, 720, 540)
            ->nonQueued();
    }
}
