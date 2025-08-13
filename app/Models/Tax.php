<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tax extends Model
{
    use HasFactory;

    protected $fillable = [
        'tax_id',
        'tax_name',
        'tax_type',
        'tax_percentage',
        'restaurant_id',
        'status'
    ];
}
