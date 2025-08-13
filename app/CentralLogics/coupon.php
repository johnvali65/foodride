<?php

namespace App\CentralLogics;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Restaurant;
use Carbon\Carbon;

class CouponLogic
{
    public static function get_discount($coupon, $order_amount)
    {
        $discount_ammount = 0;
        if ($coupon->discount_type == 'percent' && $coupon->discount > 0) {
            $discount_ammount = $order_amount * ($coupon->discount / 100);
        } else {
            $discount_ammount = $coupon->discount;
        }
        if ($coupon->max_discount > 0) {
            $discount_ammount = $discount_ammount > $coupon->max_discount ? $coupon->max_discount : $discount_ammount;
        }
        return $discount_ammount;
    }

    public static function is_valide($coupon, $user_id, $restaurant_id)
    {
        info('is_valide called');
        $start_date = Carbon::parse($coupon->start_date);
        $expire_date = Carbon::parse($coupon->expire_date);

        $today = Carbon::now();
        if ($start_date->format('Y-m-d') > $today->format('Y-m-d') || $expire_date->format('Y-m-d') < $today->format('Y-m-d')) {
            info('inside 407 error');
            return 407;  //coupon expire
        }

        if ($coupon->coupon_type == 'restaurant_wise' && !in_array($restaurant_id, json_decode($coupon->data, true))) {
            info('inside 404 1st error');
            return 404;
        } else if ($coupon->coupon_type == 'zone_wise') {
            if (json_decode($coupon->data, true)) {
                $data = Restaurant::whereIn('zone_id', json_decode($coupon->data, true))->where('id', $restaurant_id)->first();
                if (!$data) {
                    info('inside 404 2nd error');
                    return 404;
                }
            } else {
                info('inside 404 3rd error');
                return 404;
            }
        } else if ($coupon->coupon_type == 'first_order') {
            $total = Order::where(['user_id' => $user_id])->count();
            info('Coupon Limit is......' . $coupon['limit']);
            if ($total < $coupon['limit']) {
                info('inside 200 2nd error');
                return 200;
            } else {
                info('inside 406 1st error');
                return 406;//Limite orer
            }
        }
        if ($coupon['limit'] == null) {
            info('inside 200 2nd error');
            return 200;
        } else {
            $total = Order::where(['user_id' => $user_id, 'coupon_code' => $coupon['code']])->count();
            info('Coupon Limit is in else ......' . $coupon['limit']);
            if ($total < $coupon['limit']) {
                info('inside 200 3rd error');
                return 200;
            } else {
                info('inside 406 2nd error');
                return 406;//Limite orer
            }
        }
        info('inside 404 Last error');
        return 404; //not found
    }
}
