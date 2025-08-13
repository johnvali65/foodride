<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\CouponLogic;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use DB;

class CouponController extends Controller
{
    public function list(Request $request)
    {
        $awtResId = $request->res_id;

        if ($awtResId == NULL) {
            $awtResId = 0;
        }

        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }


        $zone_id = json_decode($request->header('zoneId'), true);
        $data = [];
        // try {
        $coupons = Coupon::active()->whereDate('expire_date', '>=', date('Y-m-d'))->whereDate('start_date', '<=', date('Y-m-d'))->get();

        foreach ($coupons as $key => $coupon) {
            if ($coupon->coupon_type == 'restaurant_wise') {
                $temp = Restaurant::active()->whereIn('zone_id', $zone_id)->whereIn('id', json_decode($coupon->data, true))->first();
                if ($temp) {
                    if ($awtResId == 0) {
                        $coupon->data = $temp->name;
                        $data[] = $coupon;
                    } else {
                        $awtFirstVar = json_decode($coupon->data, true);
                        $awtSecondVar = $awtFirstVar[0];
                        if ($awtSecondVar == $awtResId) {
                            $coupon->data = $temp->name;
                            $data[] = $coupon;
                        }

                    }
                    //dd($awtFirstVar[0]);exit;

                }
            } else if ($coupon->coupon_type == 'zone_wise') {
                foreach ($zone_id as $z_id) {
                    if (in_array($z_id, json_decode($coupon->data, true))) {
                        $data[] = $coupon;
                        break;
                    }
                }

            } else {
                $data[] = $coupon;
            }
            if ($coupon->display_discount == null) {
                $coupon->display_discount = '';
            }
        }

        return response()->json($data, 200);
        // } catch (\Exception $e) {
        //     return response()->json(['errors' => $e], 403);
        // }
    }

    public function apply(Request $request)
    {
        info('coupon apply API called.....' . print_r($request->all(), true));
        $validator = Validator::make($request->all(), [
            'code' => 'required',
            'restaurant_id' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        try {
            $custArr[] = (string) $request->restaurant_id;
            $strCustArr = json_encode($custArr);
            $coupon = Coupon::active()
                ->where('code', $request['code'])
                ->where('data', $strCustArr)
                ->first();
            info('coupon data inside try is....' . $coupon);
            if (!$coupon) {
                info('if coupon not exists.... ');
                $zone_id = Restaurant::where('id', $request->restaurant_id)->value('zone_id');
                info('zone id is....' . $zone_id);

                $zoneArr = [$zone_id]; // Create an array with the zone_id

                $coupons = Coupon::active()
                    ->where('code', $request['code'])
                    ->get(); // Retrieve all matching coupons

                $matchingCoupons = $coupons->filter(function ($coupon) use ($zoneArr) {
                    $data = json_decode($coupon->data, true); // Decode the JSON data from the database

                    // Check if $zoneArr value exists in the decoded $data array
                    return in_array($zoneArr[0], $data);
                });

                $coupon = $matchingCoupons->first();
                info('if zone wise.......coupon data inside try is....' . $coupon);
            }
            //$coupon = Coupon::active()->where(['code' => $request['code']])->first();
            // dd($coupon);exit;
            //'id', json_decode($coupon->data, true)
            if ($coupon->display_discount == null) {
                $coupon->display_discount = '';
            }
            info('coupon data is......' . $coupon);
            if (isset($coupon)) {
                $staus = CouponLogic::is_valide($coupon, $request->user()->id, $request['restaurant_id']);
                info('coupon status is.........' . $staus);
                switch ($staus) {
                    case 200:
                        return response()->json($coupon, 200);
                    case 406:
                        return response()->json([
                            'errors' => [
                                ['code' => 'coupon', 'message' => translate('messages.coupon_usage_limit_over')]
                            ]
                        ], 406);
                    case 407:
                        return response()->json([
                            'errors' => [
                                ['code' => 'coupon', 'message' => translate('messages.coupon_expire')]
                            ]
                        ], 407);
                    default:
                        return response()->json([
                            'errors' => [
                                ['code' => 'coupon', 'message' => translate('messages.not_found')]
                            ]
                        ], 404);
                }
            } else {
                return response()->json([
                    'errors' => [
                        ['code' => 'coupon', 'message' => translate('messages.not_found')]
                    ]
                ], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['errors' => $e], 403);
        }
    }

    public function restaurant_list(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $zoneIds = json_decode($request->header('zoneId'), true);

        // Make sure it's always an array
        if (!is_array($zoneIds)) {
            $zoneIds = [$zoneIds];
        }

        $coupons = DB::table('coupons AS c')
            ->join('restaurants AS r', function ($join) use ($zoneIds) {
                $join->where(function ($query) use ($zoneIds) {
                    $query->whereRaw("c.coupon_type = 'restaurant_wise' AND JSON_SEARCH(c.data, 'one', r.id) IS NOT NULL");

                    foreach ($zoneIds as $zoneId) {
                        $query->orWhereRaw("c.coupon_type = 'zone_wise' AND JSON_SEARCH(c.data, 'one', ?) IS NOT NULL", [$zoneId]);
                    }
                });
            })
            ->whereIn('r.zone_id', $zoneIds)
            ->where('r.status', 1)
            ->whereDate('c.start_date', '<=', now())
            ->whereDate('c.expire_date', '>=', now())
            ->select('c.*', 'r.name AS restaurant_name', 'r.id as restaurant_id', 'r.logo', 'r.footer_text')
            ->get();

        $groupedCoupons = [];

        foreach ($coupons as $coupon) {
            $couponData = [
                'id' => $coupon->id,
                'title' => $coupon->title,
                'code' => $coupon->code,
                'start_date' => $coupon->start_date,
                'expire_date' => $coupon->expire_date,
                'min_purchase' => $coupon->min_purchase,
                'max_discount' => $coupon->max_discount,
                'discount' => $coupon->discount,
                'display_discount' => (string) $coupon->display_discount,
                'discount_type' => $coupon->discount_type,
                'coupon_type' => $coupon->coupon_type,
                'limit' => $coupon->limit,
                'status' => $coupon->status,
                'data' => $coupon->data,
                'total_uses' => $coupon->total_uses,
                'awt_res_name' => $coupon->awt_res_name,
                'awt_zone_name' => $coupon->awt_zone_name,
                'restaurant_details' => []
            ];

            $restaurantDetails = [
                'restaurant_name' => $coupon->restaurant_name,
                'restaurant_id' => $coupon->restaurant_id,
                'restaurant_logo' => $coupon->logo,
                'footer_text' => $coupon->footer_text,
                'display_discount' => (string) $coupon->display_discount,
                'discount_type' => $coupon->discount_type
            ];

            if (!isset($groupedCoupons[$coupon->id])) {
                $groupedCoupons[$coupon->id] = $couponData;
            }

            $groupedCoupons[$coupon->id]['restaurant_details'][] = $restaurantDetails;
            $groupedCoupons[$coupon->id]['is_expand'] = false;
        }

        return response()->json(array_values($groupedCoupons), 200);
    }
    public function apply_latest(Request $request)
    {
        info('coupon latest apply API called.....' . print_r($request->all(), true));
        $validator = Validator::make($request->all(), [
            'code' => 'required',
            'restaurant_id' => 'required',
            'item_total' => 'required',
            'restaurant_discount' => 'required',
        ]);
        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        try {
            $custArr[] = (string) $request->restaurant_id;
            $strCustArr = json_encode($custArr);
            $coupon = Coupon::active()
                ->where('code', $request['code'])
                ->where('data', $strCustArr)
                ->first();
            info('coupon data inside try is....' . $coupon);

            if (!$coupon) {
                info('if coupon not exists.... ');
                $zone_id = Restaurant::where('id', $request->restaurant_id)->value('zone_id');
                info('zone id is....' . $zone_id);

                $zoneArr = [$zone_id]; // Create an array with the zone_id

                $coupons = Coupon::active()
                    ->where('code', $request['code'])
                    ->get(); // Retrieve all matching coupons

                $matchingCoupons = $coupons->filter(function ($coupon) use ($zoneArr) {
                    $data = json_decode($coupon->data, true); // Decode the JSON data from the database

                    // Check if $zoneArr value exists in the decoded $data array
                    return in_array($zoneArr[0], $data);
                });

                $coupon = $matchingCoupons->first();
                info('if zone wise.......coupon data inside try is....' . $coupon);
            }
            //$coupon = Coupon::active()->where(['code' => $request['code']])->first();
            // dd($coupon);exit;
            //'id', json_decode($coupon->data, true)
            if ($coupon->display_discount == null) {
                $coupon->display_discount = '';
            }
            // $total_discount = $request->item_total * $coupon->discount /100;

            // if ($coupon->max_discount < $total_discount)
            //     $total_discount = $coupon->max_discount;
            $item_total = $request->item_total - $request->restaurant_discount;
            $total_discount = min($item_total * $coupon->discount / 100, $coupon->max_discount);

            $restaurant_tax = Restaurant::where('id', $request->restaurant_id)->value('tax');
            $total_tax = ($item_total - $total_discount) * $restaurant_tax / 100;

            info('coupon data is......' . $coupon);
            if (isset($coupon)) {
                $staus = CouponLogic::is_valide($coupon, $request->user()->id, $request['restaurant_id']);
                info('coupon status is.........' . $staus);
                // return $staus;
                $coupon['tax_amount'] = round($total_tax, 0);
                // $coupon['total_discount'] = round($total_discount, 0);

                switch ($staus) {
                    case 200:
                        return response()->json($coupon, 200);
                    case 406:
                        return response()->json([
                            'errors' => [
                                ['code' => 'coupon', 'message' => translate('messages.coupon_usage_limit_over')]
                            ]
                        ], 406);
                    case 407:
                        return response()->json([
                            'errors' => [
                                ['code' => 'coupon', 'message' => translate('messages.coupon_expire')]
                            ]
                        ], 407);
                    default:
                        return response()->json([
                            'errors' => [
                                ['code' => 'coupon', 'message' => translate('messages.not_found')]
                            ]
                        ], 404);
                }
            } else {
                return response()->json([
                    'errors' => [
                        ['code' => 'coupon', 'message' => translate('messages.not_found')]
                    ]
                ], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['errors' => $e], 403);
        }
    }
}
