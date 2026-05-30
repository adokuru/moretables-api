<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TableCombination extends Model
{
    protected $fillable = [
        'restaurant_id',
        'dining_area_id',
        'table_ids',
        'min_capacity',
        'max_capacity',
    ];

    protected function casts(): array
    {
        return [
            'table_ids' => 'array',
        ];
    }

    public static function normalizeTableIds(array $tableIds): array
    {
        $tableIds = array_values(array_unique(array_map('intval', $tableIds)));
        sort($tableIds);

        return $tableIds;
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function diningArea(): BelongsTo
    {
        return $this->belongsTo(DiningArea::class);
    }
}
