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
        'title',
        'body',
        'visited_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
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
