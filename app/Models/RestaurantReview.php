<?php

namespace App\Models;

use Database\Factories\RestaurantReviewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantReview extends Model
{
    /** @use HasFactory<RestaurantReviewFactory> */
    use HasFactory;

    protected $fillable = [
        'restaurant_id',
        'user_id',
        'rating',
        'food_rating',
        'service_rating',
        'ambience_rating',
        'value_rating',
        'title',
        'body',
        'review_images',
        'visited_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'food_rating' => 'integer',
            'service_rating' => 'integer',
            'ambience_rating' => 'integer',
            'value_rating' => 'integer',
            'review_images' => 'array',
            'visited_at' => 'date',
        ];
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
