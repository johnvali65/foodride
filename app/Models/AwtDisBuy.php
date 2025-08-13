<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Restaurant;
use App\Models\Category;

class AwtDisBuy extends Model
{
	protected $table = 'awt_dis_buy_tbl'; 
    protected $primaryKey = 'discount_buy_id'; 
    protected $casts = [
        'res_id' => 'integer',
        'offer_cat_id' => 'integer',
        'min_qty' => 'integer',
        'offer_menu_id' => 'integer',
        'offer_qty' => 'integer',
        'applic_cat_id' => 'integer',
        'applic_menu_id' => 'integer'	
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'offer_cat_id');
    }    

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class, 'res_id');
    }
}