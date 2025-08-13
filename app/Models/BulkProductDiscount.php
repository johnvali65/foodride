<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Restaurant;
use App\Models\Category;

class BulkProductDiscount extends Model
{
	protected $table = 'bulk_product_discount_tbl'; 
    protected $primaryKey = 'bulk_product_discount_id'; 
    protected $casts = [
        'restaurant_id' => 'integer',
        'category_id' => 'integer',
        'value' => 'float'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }    

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class, 'restaurant_id');
    }
}
