<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TempCartItem extends Model
{
    // use HasFactory;
    protected $fillable = [
        'temp_cart_id',
        'food_id',
        'category_id',
        'product_name',
        'addons_id',
        'options_id',
        'price',
        'quantity',
        'variations',
        'variant',
        'add_ons',
        'add_on_qtys',
        'options',
        'option_qtys',
        'addon_price',
        'option_price',
        'tax',
        'status'
    ];
}
