<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryConfig extends Model
{
    protected $table = 'delivery_config';

    public static function getPriorityChannel()
    {
        $deliveryConfig = DeliveryConfig::orderBy('priority', 'ASC')
            ->where('priority', '!=', 0)
            ->first();
        return $deliveryConfig;
    }
}

