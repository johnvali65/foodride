<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryTiming extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'week_name',
        'schedule',
        'from_time',
        'to_time',
        'status'
    ];
}
