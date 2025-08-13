<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TempCart extends Model
{
    // use HasFactory;
    protected $table = 'temp_cart';

    protected $fillable = [
        'sess_cart_id',
        'user_id',
        'restaurant_id',
        'restaurant_name',
        'grand_total',
        'quantity',
        'restaurant_lat',
        'restaurant_long',
        'status'
    ];
}
