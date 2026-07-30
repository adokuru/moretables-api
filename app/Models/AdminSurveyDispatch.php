<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminSurveyDispatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_survey_id',
        'status',
        'recipients_count',
        'scheduled_at',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'recipients_count' => 'integer',
            'scheduled_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(GuestSurvey::class, 'guest_survey_id');
    }
}
