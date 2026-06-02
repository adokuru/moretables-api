<?php

namespace App\Models;

use App\MoretableLineupStatus;
use Database\Factories\MoretableLineupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MoretableLineup extends Model implements HasMedia
{
    /** @use HasFactory<MoretableLineupFactory> */
    use HasFactory;

    use InteractsWithMedia;

    protected $fillable = [
        'restaurant_id',
        'title',
        'slug',
        'excerpt',
        'body',
        'status',
        'is_featured',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MoretableLineupStatus::class,
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @param  Builder<MoretableLineup>  $query
     * @return Builder<MoretableLineup>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', MoretableLineupStatus::Published)
            ->where('published_at', '<=', now());
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('cover')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 320, 240)
            ->performOnCollections('cover')
            ->nonQueued();

        $this->addMediaConversion('card')
            ->fit(Fit::Crop, 900, 640)
            ->performOnCollections('cover')
            ->nonQueued();
    }
}
