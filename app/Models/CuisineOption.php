<?php

namespace App\Models;

use Database\Factories\CuisineOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CuisineOption extends Model
{
    /** @use HasFactory<CuisineOptionFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];
}
