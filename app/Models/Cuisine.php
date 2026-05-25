<?php

namespace App\Models;

use Database\Factories\CuisineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cuisine extends Model
{
    /** @use HasFactory<CuisineFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];
}
