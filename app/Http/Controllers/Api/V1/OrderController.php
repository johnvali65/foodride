<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\CouponLogic;
use App\CentralLogics\CustomerLogic;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\DeliveryHistory;
use App\Models\Order;
use App\Models\Coupon;
use App\Models\OrderDetail;
use App\Models\Food;
use App\Models\Restaurant;
use App\Models\ItemCampaign;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Zone;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Models\User;

class OrderController extends Controller
{
    public function track_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $order = Order::with(['restaurant', 'delivery_man.rating'])
            ->withCount('details')
            ->where([
                'id' => $request['order_id'],
                'user_id' => $request->user()->id
            ])
            ->Notpos()
            ->first();

        if (!$order) {
            return response()->json([
                'errors' => [
                    ['code' => 'schedule_at', 'message' => translate('messages.not_found')]
                ]
            ], 404);
        }

        $order['restaurant'] = $order['restaurant'] ? Helpers::restaurant_data_formatting($order['restaurant']) : $order['restaurant'];
        $order['delivery_address'] = $order['delivery_address'] ? json_decode($order['delivery_address']) : $order['delivery_address'];

        if (strtolower($order->delivery_partner) == "pidge") {
            $pidge_delivery_man = DeliveryHistory::where('order_id', $order->id)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($pidge_delivery_man) {
                $pidge_delivery_man_data = json_decode($pidge_delivery_man->location);

                if ($pidge_delivery_man_data) {
                    $data = [
                        "f_name" => $pidge_delivery_man_data->data->rider->name ?? null,
                        "phone" => $pidge_delivery_man_data->data->rider->mobile ?? null,
                        "lat" => $pidge_delivery_man_data->data->location->latitude ?? null,
                        "lng" => $pidge_delivery_man_data->data->location->longitude ?? null,
                        "location" => $pidge_delivery_man->location,
                        "created_at" => $pidge_delivery_man->created_at,
                        "updated_at" => $pidge_delivery_man->updated_at,
                        "status" => $pidge_delivery_man->status,
                    ];

                    $order['delivery_man'] = $data;
                } else {
                    $order['delivery_man'] = null;
                }
            } else {
                $order['delivery_man'] = null;
            }
        } else {
            $order['delivery_man'] = $order['delivery_man'] ? Helpers::deliverymen_data_formatting([$order['delivery_man']]) : $order['delivery_man'];
        }

        unset($order['details']);

        return response()->json($order, 200);
    }

    public function new_cust_awt_place_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_amount' => 'required',
            'payment_method' => 'required|in:cash_on_delivery,digital_payment,wallet',
            'order_type' => 'required|in:take_away,delivery',
            'restaurant_id' => 'required',
            'distance' => 'required_if:order_type,delivery',
            'address' => 'required_if:order_type,delivery',
            'longitude' => 'required_if:order_type,delivery',
            'latitude' => 'required_if:order_type,delivery',
            'dm_tips' => 'nullable|numeric'
        ]);

        //info("awtOrderAmount - ".$request->order_amount);
        //info("awtPckageCharges -".$request->awt_package_charges);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if ($request->payment_method == 'wallet' && Helpers::get_business_settings('wallet_status', false) != 1) {
            return response()->json([
                'errors' => [
                    ['code' => 'payment_method', 'message' => translate('messages.customer_wallet_disable_warning')]
                ]
            ], 203);
        }
        $coupon = null;
        $delivery_charge = $request->delivery_charge ? $request->delivery_charge : null;
        $free_delivery_by = null;
        $schedule_at = $request->schedule_at ? \Carbon\Carbon::parse($request->schedule_at) : now();
        if ($request->schedule_at && $schedule_at < now()) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_time', 'message' => translate('messages.you_can_not_schedule_a_order_in_past')]
                ]
            ], 406);
        }
        $restaurant = Restaurant::with('discount')->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = ' . $schedule_at->format('w') . ' and `restaurant_schedule`.`opening_time` < "' . $schedule_at->format('H:i:s') . '" and `restaurant_schedule`.`closing_time` >"' . $schedule_at->format('H:i:s') . '") > 0), true, false) as open')->where('id', $request->restaurant_id)->first();

        if (!$restaurant) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_time', 'message' => translate('messages.restaurant_not_found')]
                ]
            ], 404);
        }

        if ($request->schedule_at && !$restaurant->schedule_order) {
            return response()->json([
                'errors' => [
                    ['code' => 'schedule_at', 'message' => translate('messages.schedule_order_not_available')]
                ]
            ], 406);
        }

        if ($restaurant->open == false) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_time', 'message' => translate('messages.restaurant_is_closed_at_order_time'), 'awt_data' => $request->all()]
                ]
            ], 406);
        }

        if ($request['coupon_code']) {
            $custArr[] = "" . $request['restaurant_id'] . "";
            $strCustArr = json_encode($custArr);
            $coupon = Coupon::active()->where(['code' => $request['coupon_code'], 'data' => $strCustArr])->first();

            //$coupon = Coupon::active()->where(['code' => $request['coupon_code']])->first();
            if (isset($coupon)) {
                $staus = CouponLogic::is_valide($coupon, $request->user()->id, $request['restaurant_id']);
                if ($staus == 407) {
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => translate('messages.coupon_expire')]
                        ]
                    ], 407);
                } else if ($staus == 406) {
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => translate('messages.coupon_usage_limit_over')]
                        ]
                    ], 406);
                } else if ($staus == 404) {
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => translate('messages.not_found')]
                        ]
                    ], 404);
                }
                if ($coupon->coupon_type == 'free_delivery') {
                    $delivery_charge = 0;
                    $coupon = null;
                    $free_delivery_by = 'admin';
                }
            } else {
                return response()->json([
                    'errors' => [
                        ['code' => 'coupon', 'message' => translate('messages.not_found')]
                    ]
                ], 401);
            }
        }
        $per_km_shipping_charge = (float) BusinessSetting::where(['key' => 'per_km_shipping_charge'])->first()->value;
        $minimum_shipping_charge = (float) BusinessSetting::where(['key' => 'minimum_shipping_charge'])->first()->value;

        if ($request->latitude && $request->longitude) {
            $point = new Point($request->latitude, $request->longitude);
            $zone = Zone::where('id', $restaurant->zone_id)->contains('coordinates', $point)->first();
            if (!$zone) {
                $errors = [];
                array_push($errors, ['code' => 'coordinates', 'message' => translate('messages.out_of_coverage')]);
                return response()->json([
                    'errors' => $errors
                ], 403);
            }
            if ($zone->per_km_shipping_charge >= 0 && $zone->minimum_shipping_charge >= 0) {
                $per_km_shipping_charge = $zone->per_km_shipping_charge;
                $minimum_shipping_charge = $zone->minimum_shipping_charge;
            }
        }

        if ($request['order_type'] != 'take_away' && !$restaurant->free_delivery && !isset($delivery_charge)) {
            if ($restaurant->self_delivery_system) {
                $per_km_shipping_charge = $restaurant->per_km_shipping_charge;
                $minimum_shipping_charge = $restaurant->minimum_shipping_charge;
            }
        }

        if ($request->delivery_charge) {
            $original_delivery_charge = $request->delivery_charge;
        } else {
            $distance_shipping_charge = $request->distance * $per_km_shipping_charge;
            $calculated_charge = max($distance_shipping_charge, $minimum_shipping_charge);
            $original_delivery_charge = $calculated_charge;
        }

        if ($request['order_type'] == 'take_away') {
            $per_km_shipping_charge = 0;
            $minimum_shipping_charge = 0;
        }
        if (!isset($delivery_charge)) {
            $delivery_charge = ($request->distance * $per_km_shipping_charge > $minimum_shipping_charge) ? $request->distance * $per_km_shipping_charge : $minimum_shipping_charge;
        }


        $address = [
            'contact_person_name' => $request->contact_person_name ? $request->contact_person_name : $request->user()->f_name . ' ' . $request->user()->f_name,
            'contact_person_number' => $request->contact_person_number ? $request->contact_person_number : $request->user()->phone,
            'address_type' => $request->address_type ? $request->address_type : 'Delivery',
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'longitude' => (string) $request->longitude,
            'latitude' => (string) $request->latitude,
        ];

        $total_addon_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;

        $order_details = [];
        $order = new Order();
        $order->id = 100000 + Order::all()->count() + 1;
        if (Order::find($order->id)) {
            $order->id = Order::orderBy('id', 'desc')->first()->id + 1;
        }

        if ($request['cust_delivery_note'] == NULL | $request['cust_delivery_note'] == "") {
            $request['cust_delivery_note'] = "n/a";
        }

        $order->user_id = $request->user()->id;
        $order->order_amount = $request['order_amount'];

        $order->payment_status = $request['payment_method'] == 'wallet' ? 'paid' : 'unpaid';
        $order->order_status = $request['payment_method'] == 'digital_payment' ? 'failed' : ($request->payment_method == 'wallet' ? 'confirmed' : 'pending');
        $order->coupon_code = $request['coupon_code'];
        $order->payment_method = $request->payment_method;
        $order->transaction_reference = null;
        $order->order_note = $request['order_note'];
        $order->awt_delivery_notes = $request['cust_delivery_note'];
        $order->order_type = $request['order_type'];
        $order->restaurant_id = $request['restaurant_id'];
        $order->delivery_charge = round($delivery_charge, config('round_up_to_digit')) ?? 0;
        $order->original_delivery_charge = round($original_delivery_charge, config('round_up_to_digit'));
        $order->delivery_address = json_encode($address);
        $order->schedule_at = $schedule_at;
        $order->scheduled = $request->schedule_at ? 1 : 0;
        $order->awt_order_pckg_charge = $request->awt_package_charges;
        if ($order->schedule_at != NULL && $order->scheduled == 1) {
            $order->cust_order_status = "preorder";
            $order->cust_preorder = now();
        } else {
            $order->cust_order_status = "pending";
        }
        $order->otp = rand(1000, 9999);
        $order->zone_id = $restaurant->zone_id;
        $dm_tips_manage_status = BusinessSetting::where('key', 'dm_tips_status')->first()->value;
        if ($dm_tips_manage_status == 1) {
            $order->dm_tips = $request->dm_tips ?? 0;
        } else {
            $order->dm_tips = 0;
        }
        $order->pending = now();
        $order->confirmed = $request->payment_method == 'wallet' ? now() : null;
        $order->created_at = now();
        $order->updated_at = now();
        if ($request['wallet_amount_deducted'] != '') {
            $order->wallet_amount = $request['wallet_amount_deducted'];
        }

        if ($request['wallet_amount_deducted'] != '' && $request->payment_method == 'cash_on_delivery') {
            $wallet_transaction = CustomerLogic::create_wallet_transaction($request->user()->id, $request['wallet_amount_deducted'], 'order_place', $order->id);
        }

        //  info("Cart Json ".json_encode($request['cart']));

        foreach ($request['cart'] as $c) {
            if ($c['item_campaign_id'] != null) {
                $product = ItemCampaign::active()->find($c['item_campaign_id']);
                if ($product) {
                    if (count(json_decode($product['variations'], true)) > 0) {
                        $price = Helpers::variation_price($product, json_encode($c['variation']));
                    } else {
                        $price = $product['price'];
                    }
                    $product->tax = $restaurant->tax;
                    $product = Helpers::product_data_formatting($product, false, false, app()->getLocale());
                    $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id', $c['add_on_ids'])->get(), $c['add_on_qtys']);
                    // info("AWT Offer String -> ".$c['awt_buy_N_get_n']);
                    $or_d = [
                        'food_id' => null,
                        'item_campaign_id' => $c['item_campaign_id'],
                        'food_details' => json_encode($product),
                        'quantity' => $c['quantity'],
                        'price' => $price,
                        'tax_amount' => Helpers::tax_calculate($product, $price),
                        'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
                        'discount_type' => 'discount_on_product',
                        'variant' => json_encode($c['variant']),
                        'variation' => json_encode($c['variation']),
                        'add_ons' => json_encode($addon_data['addons']),
                        'total_add_on_price' => $addon_data['total_add_on_price'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $order_details[] = $or_d;
                    $total_addon_price += $or_d['total_add_on_price'];
                    $product_price += $price * $or_d['quantity'];
                    $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'];
                } else {
                    return response()->json([
                        'errors' => [
                            ['code' => 'campaign', 'message' => translate('messages.product_unavailable_warning')]
                        ]
                    ], 401);
                }
            } else {
                $product = Food::active()->find($c['food_id']);
                if ($product) {
                    if (count(json_decode($product['variations'], true)) > 0) {
                        $price = Helpers::variation_price($product, json_encode($c['variation']));
                    } else {
                        $price = $product['price'];
                    }
                    $product->tax = $restaurant->tax;
                    $product = Helpers::product_data_formatting($product, false, false, app()->getLocale());
                    $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id', $c['add_on_ids'])->get(), $c['add_on_qtys']);
                    //  info("AWT Offer String -> ".$c['awt_buy_N_get_n']);
                    //  info("AWT Offer String -> ".$c['awt_offer_product_type']);
                    $var_off_prod_type = $c['awt_offer_product_type'];
                    $var_off_str = $c['awt_buy_N_get_n'];

                    $mysqli = mysqli_connect("localhost", "manicmoon", "Vizag@4532", "foodrsiv_newfrdb");
                    $qry = "SELECT discount_buy_id, discount_title, city_id, rest_id, offer_cat_id, offer_menu_id, min_qty, offer_qty, applic_cat_id, applic_menu_id FROM awt_dis_buy_tbl WHERE applic_menu_id=" . $c['food_id'];
                    $rslt = mysqli_query($mysqli, $qry);
                    $recCount = mysqli_num_rows($rslt);
                    // info("Qry - ".$qry);
                    // info("Record Count Offer - ".$recCount);
                    if ($recCount > 0) {
                        $price = 0;
                    }
                    $or_d = [
                        'food_id' => $c['food_id'],
                        'item_campaign_id' => null,
                        'food_details' => json_encode($product),
                        'quantity' => $c['quantity'],
                        'price' => round($price, config('round_up_to_digit')),
                        'tax_amount' => round(Helpers::tax_calculate($product, $price), config('round_up_to_digit')),
                        'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
                        'discount_type' => 'discount_on_product',
                        'variant' => json_encode($c['variant']),
                        'variation' => json_encode($c['variation']),
                        'add_ons' => json_encode($addon_data['addons']),
                        'total_add_on_price' => round($addon_data['total_add_on_price'], config('round_up_to_digit')),
                        'awt_offer_food_str' => $var_off_str,
                        'awt_offer_food_type' => $var_off_prod_type,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $total_addon_price += $or_d['total_add_on_price'];
                    $product_price += $price * $or_d['quantity'];
                    $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'];
                    $order_details[] = $or_d;
                } else {
                    return response()->json([
                        'errors' => [
                            ['code' => 'food', 'message' => translate('messages.product_unavailable_warning')]
                        ]
                    ], 401);
                }
            }

        }
        $restaurant_discount = Helpers::get_restaurant_discount($restaurant);
        if (isset($restaurant_discount)) {
            if ($product_price + $total_addon_price < $restaurant_discount['min_purchase']) {
                $restaurant_discount_amount = 0;
            }

            if ($restaurant_discount_amount > $restaurant_discount['max_discount']) {
                $restaurant_discount_amount = $restaurant_discount['max_discount'];
            }
        }
        $coupon_discount_amount = $coupon ? CouponLogic::get_discount($coupon, $product_price + $total_addon_price - $restaurant_discount_amount) : 0;
        $total_price = $product_price + $total_addon_price - $restaurant_discount_amount - $coupon_discount_amount;

        $tax = $restaurant->tax;
        $total_tax_amount = ($tax > 0) ? (($total_price * $tax) / 100) : 0;

        if ($restaurant->minimum_order > $product_price + $total_addon_price) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_time', 'message' => translate('messages.you_need_to_order_at_least', ['amount' => $restaurant->minimum_order . ' ' . Helpers::currency_code()])]
                ]
            ], 406);
        }

        $free_delivery_over = BusinessSetting::where('key', 'free_delivery_over')->first()->value;
        if (isset($free_delivery_over)) {
            if ($free_delivery_over <= $product_price + $total_addon_price - $coupon_discount_amount - $restaurant_discount_amount) {
                $order->delivery_charge = 0;
                $free_delivery_by = 'admin';
            }
        }

        if ($restaurant->free_delivery) {
            $order->delivery_charge = 0;
            $free_delivery_by = 'vendor';
        }

        if ($coupon) {
            $coupon->increment('total_uses');
        }

        if ($request->awt_package_charges != NULL) {
            $awt_package_charges = $request->awt_package_charges;
        } else {
            $awt_package_charges = $request->awt_package_charges;
        }

        $order_amount = round($total_price + $total_tax_amount + $awt_package_charges + $order->delivery_charge, config('round_up_to_digit'));

        if ($request->payment_method == 'wallet' && $request->user()->wallet_balance < $order_amount) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_amount', 'message' => translate('messages.insufficient_balance')]
                ]
            ], 203);
        }
        // dd($order->dm_tips);
        try {
            $order->coupon_discount_amount = round($coupon_discount_amount, config('round_up_to_digit'));
            $order->coupon_discount_title = $coupon ? $coupon->title : '';
            $order->free_delivery_by = $free_delivery_by;
            $order->restaurant_discount_amount = round($restaurant_discount_amount, config('round_up_to_digit'));
            $order->total_tax_amount = round($total_tax_amount, config('round_up_to_digit'));
            // $order->order_amount = $order_amount + $order->dm_tips;
            $order->order_amount = $order_amount;
            $order->save();
            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;
            }
            OrderDetail::insert($order_details);
            Helpers::send_order_notification($order);

            $customer = $request->user();
            $customer->zone_id = $restaurant->zone_id;
            $customer->save();

            $restaurant->increment('total_order');
            if ($request->payment_method == 'wallet')
                CustomerLogic::create_wallet_transaction($order->user_id, $order->order_amount, 'order_place', $order->id);

            try {
                if ($order->order_status == 'pending') {
                    Mail::to($customer['email'])->send(new \App\Mail\OrderPlaced($order->id));
                }
            } catch (\Exception $ex) {
                info($ex);
            }

            try {
                if ($order->order_status == 'pending' || $order->order_status == 'confirmed') {
                    $mobile = $customer['phone'];
                    $message = 'Dear ' . $customer['f_name'] . '

Order has been placed @ ' . date('d M Y, h:i A', strtotime($order->schedule_at)) . '- for store ' . $restaurant['name'] . ' Order ID: ' . $order->id . '

Thanks and Regards
Food Ride';
                    $template_id = 1407169268569030169;
                    Helpers::send_cmoon_sms($mobile, $message, $template_id);
                }
            } catch (\Exception $ex) {
                info($ex);
            }

            return response()->json([
                'message' => translate('messages.order_placed_successfully'),
                'order_id' => $order->id,
                'total_ammount' => $total_price + $order->delivery_charge + $total_tax_amount
            ], 200);
        } catch (\Exception $e) {
            info($e);
            return response()->json([$e], 403);
        }

        // return response()->json([
        //     'errors' => [
        //         ['code' => 'order_time', 'message' => translate('messages.failed_to_place_order')]
        //     ]
        // ], 403);
    }

    public function awt_order_push_notification_driver(Request $request)
    {
        $orderId = $request->order_id;
        //   dd($orderId);exit;
        $order = Order::where('id', $orderId)
            ->Notpos()
            ->first();

        /*$order = Order::whereHas('restaurant.vendor', function($query) use($vendor){
        $query->where('id', $vendor->id);
        })
        ->where('id', $request['order_id'])
        ->Notpos()
        ->first();*/
        //dd($order);exit;

        $order['is_cronjob_call_done'] = 1;
        $order->save();

        Helpers::awt_alpha_manual_send_order_notification($order);

        return response()->json(['message' => 'Custom Alpha Push Sent'], 200);
    }


    public function place_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_amount' => 'required',
            'payment_method' => 'required|in:cash_on_delivery,digital_payment,wallet',
            'order_type' => 'required|in:take_away,delivery',
            'restaurant_id' => 'required',
            'distance' => 'required_if:order_type,delivery',
            'address' => 'required_if:order_type,delivery',
            'longitude' => 'required_if:order_type,delivery',
            'latitude' => 'required_if:order_type,delivery',
            'dm_tips' => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if ($request->payment_method == 'wallet' && Helpers::get_business_settings('wallet_status', false) != 1) {
            return response()->json([
                'errors' => [
                    ['code' => 'payment_method', 'message' => translate('messages.customer_wallet_disable_warning')]
                ]
            ], 203);
        }
        $coupon = null;
        $delivery_charge = $request->delivery_charge ? $request->delivery_charge : null;
        $free_delivery_by = null;
        $schedule_at = $request->schedule_at ? \Carbon\Carbon::parse($request->schedule_at) : now();
        if ($request->schedule_at && $schedule_at < now()) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_time', 'message' => translate('messages.you_can_not_schedule_a_order_in_past')]
                ]
            ], 406);
        }
        $restaurant = Restaurant::with('discount')->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = ' . $schedule_at->format('w') . ' and `restaurant_schedule`.`opening_time` < "' . $schedule_at->format('H:i:s') . '" and `restaurant_schedule`.`closing_time` >"' . $schedule_at->format('H:i:s') . '") > 0), true, false) as open')->where('id', $request->restaurant_id)->first();

        if (!$restaurant) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_time', 'message' => translate('messages.restaurant_not_found')]
                ]
            ], 404);
        }

        if ($request->schedule_at && !$restaurant->schedule_order) {
            return response()->json([
                'errors' => [
                    ['code' => 'schedule_at', 'message' => translate('messages.schedule_order_not_available')]
                ]
            ], 406);
        }

        if ($restaurant->open == false) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_time', 'message' => translate('messages.restaurant_is_closed_at_order_time'), 'awt_data' => $request->all()]
                ]
            ], 406);
        }

        if ($request['coupon_code']) {
            $custArr[] = "" . $request['restaurant_id'] . "";
            $strCustArr = json_encode($custArr);
            $coupon = Coupon::active()->where(['code' => $request['coupon_code'], 'data' => $strCustArr])->first();

            //$coupon = Coupon::active()->where(['code' => $request['coupon_code']])->first();
            if (isset($coupon)) {
                $staus = CouponLogic::is_valide($coupon, $request->user()->id, $request['restaurant_id']);
                if ($staus == 407) {
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => translate('messages.coupon_expire')]
                        ]
                    ], 407);
                } else if ($staus == 406) {
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => translate('messages.coupon_usage_limit_over')]
                        ]
                    ], 406);
                } else if ($staus == 404) {
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => translate('messages.not_found')]
                        ]
                    ], 404);
                }
                if ($coupon->coupon_type == 'free_delivery') {
                    $delivery_charge = 0;
                    $coupon = null;
                    $free_delivery_by = 'admin';
                }
            } else {
                return response()->json([
                    'errors' => [
                        ['code' => 'coupon', 'message' => translate('messages.not_found')]
                    ]
                ], 401);
            }
        }
        $per_km_shipping_charge = (float) BusinessSetting::where(['key' => 'per_km_shipping_charge'])->first()->value;
        $minimum_shipping_charge = (float) BusinessSetting::where(['key' => 'minimum_shipping_charge'])->first()->value;

        if ($request->latitude && $request->longitude) {
            $point = new Point($request->latitude, $request->longitude);
            $zone = Zone::where('id', $restaurant->zone_id)->contains('coordinates', $point)->first();
            if (!$zone) {
                $errors = [];
                array_push($errors, ['code' => 'coordinates', 'message' => translate('messages.out_of_coverage')]);
                return response()->json([
                    'errors' => $errors
                ], 403);
            }
            if ($zone->per_km_shipping_charge >= 0 && $zone->minimum_shipping_charge >= 0) {
                $per_km_shipping_charge = $zone->per_km_shipping_charge;
                $minimum_shipping_charge = $zone->minimum_shipping_charge;
            }
        }

        if ($request['order_type'] != 'take_away' && !$restaurant->free_delivery && !isset($delivery_charge)) {
            if ($restaurant->self_delivery_system) {
                $per_km_shipping_charge = $restaurant->per_km_shipping_charge;
                $minimum_shipping_charge = $restaurant->minimum_shipping_charge;
            }
        }

        if ($request->delivery_charge) {
            $original_delivery_charge = $request->delivery_charge;
        } else {
            $distance_shipping_charge = $request->distance * $per_km_shipping_charge;
            $calculated_charge = max($distance_shipping_charge, $minimum_shipping_charge);
            $original_delivery_charge = $calculated_charge;
        }

        if ($request['order_type'] == 'take_away') {
            $per_km_shipping_charge = 0;
            $minimum_shipping_charge = 0;
        }
        if (!isset($delivery_charge)) {
            $delivery_charge = ($request->distance * $per_km_shipping_charge > $minimum_shipping_charge) ? $request->distance * $per_km_shipping_charge : $minimum_shipping_charge;
        }


        $address = [
            'contact_person_name' => $request->contact_person_name ? $request->contact_person_name : $request->user()->f_name . ' ' . $request->user()->f_name,
            'contact_person_number' => $request->contact_person_number ? $request->contact_person_number : $request->user()->phone,
            'address_type' => $request->address_type ? $request->address_type : 'Delivery',
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'longitude' => (string) $request->longitude,
            'latitude' => (string) $request->latitude,
        ];

        $total_addon_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;

        $order_details = [];
        $order = new Order();
        $order->id = 100000 + Order::all()->count() + 1;
        if (Order::find($order->id)) {
            $order->id = Order::orderBy('id', 'desc')->first()->id + 1;
        }

        if ($request['cust_delivery_note'] == NULL | $request['cust_delivery_note'] == "") {
            $request['cust_delivery_note'] = "n/a";
        }

        $order->user_id = $request->user()->id;
        $order->order_amount = $request['order_amount'];

        $order->payment_status = $request['payment_method'] == 'wallet' ? 'paid' : 'unpaid';
        $order->order_status = $request['payment_method'] == 'digital_payment' ? 'failed' : ($request->payment_method == 'wallet' ? 'confirmed' : 'pending');
        $order->coupon_code = $request['coupon_code'];
        $order->payment_method = $request->payment_method;
        $order->transaction_reference = null;
        $order->order_note = $request['order_note'];
        $order->awt_delivery_notes = $request['cust_delivery_note'];
        $order->order_type = $request['order_type'];
        $order->restaurant_id = $request['restaurant_id'];
        $order->delivery_charge = round($delivery_charge, config('round_up_to_digit')) ?? 0;
        $order->original_delivery_charge = round($original_delivery_charge, config('round_up_to_digit'));
        $order->delivery_address = json_encode($address);
        $order->schedule_at = $schedule_at;
        $order->scheduled = $request->schedule_at ? 1 : 0;
        if ($order->schedule_at != NULL && $order->scheduled == 1) {
            $order->cust_order_status = "preorder";
            $order->cust_preorder = now();
        } else {
            $order->cust_order_status = "pending";
        }
        $order->otp = rand(1000, 9999);
        $order->zone_id = $restaurant->zone_id;
        $dm_tips_manage_status = BusinessSetting::where('key', 'dm_tips_status')->first()->value;
        if ($dm_tips_manage_status == 1) {
            $order->dm_tips = $request->dm_tips ?? 0;
        } else {
            $order->dm_tips = 0;
        }
        $order->pending = now();
        $order->confirmed = $request->payment_method == 'wallet' ? now() : null;
        $order->created_at = now();
        $order->updated_at = now();
        foreach ($request['cart'] as $c) {
            if ($c['item_campaign_id'] != null) {
                $product = ItemCampaign::active()->find($c['item_campaign_id']);
                if ($product) {
                    if (count(json_decode($product['variations'], true)) > 0) {
                        $price = Helpers::variation_price($product, json_encode($c['variation']));
                    } else {
                        $price = $product['price'];
                    }
                    $product->tax = $restaurant->tax;
                    $product = Helpers::product_data_formatting($product, false, false, app()->getLocale());
                    $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id', $c['add_on_ids'])->get(), $c['add_on_qtys']);
                    $or_d = [
                        'food_id' => null,
                        'item_campaign_id' => $c['item_campaign_id'],
                        'food_details' => json_encode($product),
                        'quantity' => $c['quantity'],
                        'price' => $price,
                        'tax_amount' => Helpers::tax_calculate($product, $price),
                        'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
                        'discount_type' => 'discount_on_product',
                        'variant' => json_encode($c['variant']),
                        'variation' => json_encode($c['variation']),
                        'add_ons' => json_encode($addon_data['addons']),
                        'total_add_on_price' => $addon_data['total_add_on_price'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $order_details[] = $or_d;
                    $total_addon_price += $or_d['total_add_on_price'];
                    $product_price += $price * $or_d['quantity'];
                    $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'];
                } else {
                    return response()->json([
                        'errors' => [
                            ['code' => 'campaign', 'message' => translate('messages.product_unavailable_warning')]
                        ]
                    ], 401);
                }
            } else {
                $product = Food::active()->find($c['food_id']);
                if ($product) {
                    if (count(json_decode($product['variations'], true)) > 0) {
                        $price = Helpers::variation_price($product, json_encode($c['variation']));
                    } else {
                        $price = $product['price'];
                    }
                    $product->tax = $restaurant->tax;
                    $product = Helpers::product_data_formatting($product, false, false, app()->getLocale());
                    $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id', $c['add_on_ids'])->get(), $c['add_on_qtys']);
                    $or_d = [
                        'food_id' => $c['food_id'],
                        'item_campaign_id' => null,
                        'food_details' => json_encode($product),
                        'quantity' => $c['quantity'],
                        'price' => round($price, config('round_up_to_digit')),
                        'tax_amount' => round(Helpers::tax_calculate($product, $price), config('round_up_to_digit')),
                        'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
                        'discount_type' => 'discount_on_product',
                        'variant' => json_encode($c['variant']),
                        'variation' => json_encode($c['variation']),
                        'add_ons' => json_encode($addon_data['addons']),
                        'total_add_on_price' => round($addon_data['total_add_on_price'], config('round_up_to_digit')),
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $total_addon_price += $or_d['total_add_on_price'];
                    $product_price += $price * $or_d['quantity'];
                    $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'];
                    $order_details[] = $or_d;
                } else {
                    return response()->json([
                        'errors' => [
                            ['code' => 'food', 'message' => translate('messages.product_unavailable_warning')]
                        ]
                    ], 401);
                }
            }

        }
        $restaurant_discount = Helpers::get_restaurant_discount($restaurant);
        if (isset($restaurant_discount)) {
            if ($product_price + $total_addon_price < $restaurant_discount['min_purchase']) {
                $restaurant_discount_amount = 0;
            }

            if ($restaurant_discount_amount > $restaurant_discount['max_discount']) {
                $restaurant_discount_amount = $restaurant_discount['max_discount'];
            }
        }
        $coupon_discount_amount = $coupon ? CouponLogic::get_discount($coupon, $product_price + $total_addon_price - $restaurant_discount_amount) : 0;
        $total_price = $product_price + $total_addon_price - $restaurant_discount_amount - $coupon_discount_amount;

        $tax = $restaurant->tax;
        $total_tax_amount = ($tax > 0) ? (($total_price * $tax) / 100) : 0;

        if ($restaurant->minimum_order > $product_price + $total_addon_price) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_time', 'message' => translate('messages.you_need_to_order_at_least', ['amount' => $restaurant->minimum_order . ' ' . Helpers::currency_code()])]
                ]
            ], 406);
        }

        $free_delivery_over = BusinessSetting::where('key', 'free_delivery_over')->first()->value;
        if (isset($free_delivery_over)) {
            if ($free_delivery_over <= $product_price + $total_addon_price - $coupon_discount_amount - $restaurant_discount_amount) {
                $order->delivery_charge = 0;
                $free_delivery_by = 'admin';
            }
        }

        if ($restaurant->free_delivery) {
            $order->delivery_charge = 0;
            $free_delivery_by = 'vendor';
        }

        if ($coupon) {
            $coupon->increment('total_uses');
        }

        $order_amount = round($total_price + $total_tax_amount + $order->delivery_charge, config('round_up_to_digit'));

        if ($request->payment_method == 'wallet' && $request->user()->wallet_balance < $order_amount) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_amount', 'message' => translate('messages.insufficient_balance')]
                ]
            ], 203);
        }
        // dd($order->dm_tips);
        try {
            $order->coupon_discount_amount = round($coupon_discount_amount, config('round_up_to_digit'));
            $order->coupon_discount_title = $coupon ? $coupon->title : '';
            $order->free_delivery_by = $free_delivery_by;
            $order->restaurant_discount_amount = round($restaurant_discount_amount, config('round_up_to_digit'));
            $order->total_tax_amount = round($total_tax_amount, config('round_up_to_digit'));
            // $order->order_amount = $order_amount + $order->dm_tips;
            $order->order_amount = $order_amount;
            $order->save();
            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;
            }
            OrderDetail::insert($order_details);
            Helpers::send_order_notification($order);

            $customer = $request->user();
            $customer->zone_id = $restaurant->zone_id;
            $customer->save();

            $restaurant->increment('total_order');
            if ($request->payment_method == 'wallet')
                CustomerLogic::create_wallet_transaction($order->user_id, $order->order_amount, 'order_place', $order->id);

            try {
                if ($order->order_status == 'pending') {
                    Mail::to($customer['email'])->send(new \App\Mail\OrderPlaced($order->id));
                }
            } catch (\Exception $ex) {
                info($ex);
            }
            return response()->json([
                'message' => translate('messages.order_placed_successfully'),
                'order_id' => $order->id,
                'total_ammount' => $total_price + $order->delivery_charge + $total_tax_amount
            ], 200);
        } catch (\Exception $e) {
            info($e);
            return response()->json([$e], 403);
        }

        // return response()->json([
        //     'errors' => [
        //         ['code' => 'order_time', 'message' => translate('messages.failed_to_place_order')]
        //     ]
        // ], 403);
    }

    public function awt_order_auto_assign_cron_job(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $paginator = Order::with(['restaurant', 'delivery_man.rating'])->withCount('details')->where(['is_delivery_call_done' => 0])->whereIn('order_status', ['processing'])->Notpos()->latest()->paginate($request['limit'], ['*'], 'page', $request['offset']);
        $orders = array_map(function ($data) {
            $data['delivery_address'] = $data['delivery_address'] ? json_decode($data['delivery_address']) : $data['delivery_address'];
            $data['restaurant'] = $data['restaurant'] ? Helpers::restaurant_data_formatting($data['restaurant']) : $data['restaurant'];
            // $data['delivery_man'] = $data['delivery_man']?Helpers::deliverymen_data_formatting([$data['delivery_man']]):$data['delivery_man'];
            return $data;
        }, $paginator->items());
        $data = [
            'total_size' => $paginator->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'orders' => $orders
        ];

        // Helpers::awt_send_order_notification($order);

        return response()->json($data, 200);
    }

    public function get_order_list(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $paginator = Order::with(['restaurant', 'delivery_man.rating'])->withCount('details')->where(['user_id' => $request->user()->id])->whereIn('order_status', ['delivered', 'canceled', 'refund_requested', 'refunded', 'failed'])->Notpos()->latest()->paginate($request['limit'], ['*'], 'page', $request['offset']);
        $cancellation_time_period = BusinessSetting::where('key', 'cancellation_time_period')->value('value');
        $orders = array_map(function ($data) {
            $data['delivery_address'] = $data['delivery_address'] ? json_decode($data['delivery_address']) : $data['delivery_address'];
            $data['restaurant'] = $data['restaurant'] ? Helpers::restaurant_data_formatting($data['restaurant']) : $data['restaurant'];
            $data['delivery_man'] = $data['delivery_man'] ? Helpers::deliverymen_data_formatting([$data['delivery_man']]) : $data['delivery_man'];
            return $data;
        }, $paginator->items());
        foreach ($orders as $order) {
            $cancel_button_visible_upto = Carbon::parse($order->created_at)->addSeconds($cancellation_time_period);
            $order->cancel_button_visible_upto = date('Y-m-d H:i:s', strtotime($cancel_button_visible_upto));
            $order->cancellation_time_period = $cancellation_time_period;
        }
        $data = [
            'total_size' => $paginator->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'orders' => $orders
        ];
        return response()->json($data, 200);
    }


    public function get_running_orders(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $paginator = Order::with(['restaurant', 'delivery_man.rating'])->withCount('details')->where(['user_id' => $request->user()->id])->whereNotIn('order_status', ['delivered', 'canceled', 'refund_requested', 'refunded', 'failed'])->Notpos()->latest()->paginate($request['limit'], ['*'], 'page', $request['offset']);
        $cancellation_time_period = BusinessSetting::where('key', 'cancellation_time_period')->value('value');
        $orders = array_map(function ($data) {
            $data['delivery_address'] = $data['delivery_address'] ? json_decode($data['delivery_address']) : $data['delivery_address'];
            $data['restaurant'] = $data['restaurant'] ? Helpers::restaurant_data_formatting($data['restaurant']) : $data['restaurant'];
            $data['delivery_man'] = $data['delivery_man'] ? Helpers::deliverymen_data_formatting([$data['delivery_man']]) : $data['delivery_man'];
            return $data;
        }, $paginator->items());
        foreach ($orders as $order) {
            $cancel_button_visible_upto = Carbon::parse($order->created_at)->addSeconds($cancellation_time_period);
            $order->cancel_button_visible_upto = date('Y-m-d H:i:s', strtotime($cancel_button_visible_upto));
            $order->cancellation_time_period = $cancellation_time_period;
        }
        $data = [
            'total_size' => $paginator->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'orders' => $orders
        ];
        return response()->json($data, 200);
    }

    public function get_order_details(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $details = OrderDetail::whereHas('order', function ($query) use ($request) {
            return $query->where('user_id', $request->user()->id);
        })->where(['order_id' => $request['order_id']])->get();
        if ($details->count() > 0) {
            $details = $details = Helpers::order_details_data_formatting($details);
            return response()->json($details, 200);
        } else {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('messages.not_found')]
                ]
            ], 401);
        }
    }

    public function cancel_order(Request $request)
    {
        $order = Order::where(['user_id' => $request->user()->id, 'id' => $request['order_id']])->Notpos()->first();
        if (!$order) {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('messages.not_found')]
                ]
            ], 401);
        } else if ($order->order_status == 'pending') {
            if ($order->payment_method == "cash_on_delivery" || $order->payment_status == "unpaid") {
                if ($order->wallet_amount != 0 && BusinessSetting::where('key', 'wallet_add_refund')->first()->value == 1) {
                    CustomerLogic::create_wallet_transaction($order->user_id, $order->wallet_amount, 'order_refund', $order->id);
                    $order->order_status = 'refunded';
                    $order->refunded = now();
                    $order->save();
                } else {
                    $order->order_status = 'canceled';
                    $order->canceled = now();
                    $order->save();
                }
            } else if ($order->payment_status == "paid" && BusinessSetting::where('key', 'wallet_add_refund')->first()->value == 1) {
                CustomerLogic::create_wallet_transaction($order->user_id, $order->order_amount + $order->restaurant_discount_amount, 'order_refund', $order->id);
                $order->order_status = 'refunded';
                $order->refunded = now();
                $order->save();
            } else {
                $order->order_status = 'canceled';
                $order->canceled = now();
                $order->save();
                try {
                    Helpers::send_order_notification($order);
                } catch (\Exception $e) {
                    info($e);
                }
                return response()->json(['message' => translate('messages.order_canceled_successfully')], 200);
            }

            if (!Helpers::send_order_notification($order)) {
                Toastr::warning(translate('messages.push_notification_faild'));
            }
            $order_id = $order['id'];
            $user_id = $order['user_id'];
            $awt_status = $order['order_status'];
            $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status);
            return response()->json(['message' => translate('messages.order_canceled_successfully')], 200);

        }
        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => translate('messages.you_can_not_cancel_after_confirm')]
            ]
        ], 401);
    }

    public function refund_request(Request $request)
    {
        $order = Order::where(['user_id' => $request->user()->id, 'id' => $request['order_id']])->Notpos()->first();
        if (!$order) {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('messages.not_found')]
                ]
            ], 401);
        } else if ($order->order_status == 'delivered') {

            $order->order_status = 'refund_requested';
            $order->refund_requested = now();
            $order->save();
            return response()->json(['message' => translate('messages.refund_request_placed_successfully')], 200);
        }
        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => translate('messages.you_can_not_request_for_refund_after_delivery')]
            ]
        ], 401);
    }

    public function update_payment_method(Request $request)
    {
        $config = Helpers::get_business_settings('cash_on_delivery');
        if ($config['status'] == 0) {
            return response()->json([
                'errors' => [
                    ['code' => 'cod', 'message' => translate('messages.Cash on delivery order not available at this time')]
                ]
            ], 403);
        }
        $order = Order::where(['user_id' => $request->user()->id, 'id' => $request['order_id']])->Notpos()->first();
        if ($order) {
            Order::where(['user_id' => $request->user()->id, 'id' => $request['order_id']])->update([
                'payment_method' => 'cash_on_delivery',
                'order_status' => 'pending',
                'pending' => now()
            ]);

            $fcm_token = $request->user()->cm_firebase_token;
            $value = Helpers::order_status_update_message('pending');
            try {
                if ($value) {
                    $data = [
                        'title' => translate('messages.order_placed_successfully'),
                        'description' => $value,
                        'order_id' => $order->id,
                        'image' => '',
                        'type' => 'order_status',
                    ];
                    Helpers::send_push_notif_to_device($fcm_token, $data);
                    DB::table('user_notifications')->insert([
                        'data' => json_encode($data),
                        'user_id' => $request->user()->id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
                if ($order->order_type == 'delivery' && !$order->scheduled) {
                    $data = [
                        'title' => translate('messages.order_placed_successfully'),
                        'description' => translate('messages.new_order_push_description'),
                        'order_id' => $order->id,
                        'image' => '',
                    ];
                    Helpers::send_push_notif_to_topic($data, $order->restaurant->zone->deliveryman_wise_topic, 'order_request');
                }

            } catch (\Exception $e) {
                info($e);
            }
            return response()->json(['message' => translate('messages.payment') . ' ' . translate('messages.method') . ' ' . translate('messages.updated_successfully')], 200);
        }
        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => translate('messages.not_found')]
            ]
        ], 404);
    }

    public function awt_get_running_orders(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $paginator = Order::with(['restaurant', 'delivery_man.rating'])
            ->withCount('details')
            ->where('user_id', $request->user()->id)
            ->whereNotIn('order_status', ['refund_requested'])
            ->Notpos()
            ->latest()
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        $cancellation_time_period = BusinessSetting::where('key', 'cancellation_time_period')->value('value');

        $orders = [];
        foreach ($paginator->items() as $data) {
            $data['delivery_address'] = $data['delivery_address'] ? json_decode($data['delivery_address']) : $data['delivery_address'];
            $data['restaurant'] = $data['restaurant'] ? Helpers::restaurant_data_formatting($data['restaurant']) : $data['restaurant'];
            $data['delivery_man'] = $data['delivery_man'] ? Helpers::deliverymen_data_formatting([$data['delivery_man']]) : $data['delivery_man'];
            $orders[] = $data;
        }

        foreach ($orders as $order) {
            $cancel_button_visible_upto = Carbon::parse($order->created_at)->addSeconds($cancellation_time_period);
            $order->cancel_button_visible_upto = date('Y-m-d H:i:s', strtotime($cancel_button_visible_upto));
            $order->cancellation_time_period = $cancellation_time_period;
        }

        $data = [
            'total_size' => $paginator->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'orders' => $orders
        ];

        return response()->json($data, 200);

    }

    public function awt_get_running_orders_latest(Request $request)
    {
        // $validator = Validator::make($request->all(), [
        //     'limit' => 'required',
        //     'offset' => 'required',
        // ]);

        // if ($validator->fails()) {
        //     return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        // }

        $paginator = Order::with([
            'restaurant:id,name,phone,logo,address,delivery_time',
            'delivery_man:id,f_name,l_name,phone,image',
            'delivery_man.rating'
        ])
            ->withCount('details')
            ->where('user_id', $request->user()->id)
            ->whereNotIn('order_status', ['refund_requested'])
            ->Notpos()
            ->latest()
            // ->paginate($request['limit'], ['*'], 'page', $request['offset']);
            ->select(
                'id',
                'order_amount',
                'delivery_charge',
                'otp',
                'awt_schedule_time',
                'order_status',
                'created_at',
                'pending',
                'confirmed',
                'processing',
                'handover',
                'picked_up',
                'delivered',
                'canceled',
                'awt_is_review_done',
                'scheduled',
                'awtOrderCartDetails',
                'delivery_man_id',
                'restaurant_id',
                'cust_preorder',
                'schedule_at',
                'refunded',
                'accepted',
                'restaurant_discount_amount'
            );
        if ($request->type != '') {
            if ($request->type == 'on_going') {
                $paginator = $paginator->whereNotIn('order_status', ['delivered', 'canceled', 'refund_requested', 'refunded', 'failed']);
            } else if ($request->type == 'completed') {
                $paginator = $paginator->where('order_status', 'delivered');
            } else if ($request->type == 'canceled') {
                $paginator = $paginator->whereIn('order_status', ['canceled', 'refund_requested', 'refunded']);
            }
        }
        $paginator = $paginator->paginate(5);

        $cancellation_time_period = BusinessSetting::where('key', 'cancellation_time_period')->value('value');

        $orders = [];
        foreach ($paginator->getCollection() as $data) {
            $data['delivery_address'] = $data['delivery_address'] ? json_decode($data['delivery_address']) : $data['delivery_address'];
            $data['restaurant'] = $data['restaurant'] ? Helpers::restaurant_data_formatting($data['restaurant']) : $data['restaurant'];
            $data['delivery_man'] = $data['delivery_man'] ? Helpers::deliverymen_data_formatting([$data['delivery_man']]) : $data['delivery_man'];
            $orders[] = $data;
        }

        foreach ($orders as $order) {
            $cancel_button_visible_upto = Carbon::parse($order->created_at)->addSeconds($cancellation_time_period);
            $order->cancel_button_visible_upto = date('Y-m-d H:i:s', strtotime($cancel_button_visible_upto));
            $order->cancellation_time_period = '0';
            // $order->order_amount = $order->order_amount + $order->restaurant_discount_amount;
        }
        info("awt_get_running_orders_latest......" . json_encode($paginator));
        $data = [
            'status' => 'valid',
            'message' => 'success',
            'orders' => $paginator
        ];

        return response()->json($data, 200);

    }

    public function new_cust_app_awt_place_order(Request $request)
    {

        //  info("awtOrderAmount - ".$request->order_amount);
        //   info("awtPckageCharges -".$request->awt_package_charges);

        //info("called awt_place_order method");
        //info("Post  data ");
        info(json_encode($request->all()));
        $validator = Validator::make($request->all(), [
            'order_amount' => 'required',
            'payment_method' => 'required|in:cash_on_delivery,digital_payment,razor_pay,wallet',
            'order_type' => 'required|in:take_away,delivery',
            'restaurant_id' => 'required',
            'distance' => 'required_if:order_type,delivery',
            'address' => 'required_if:order_type,delivery',
            'longitude' => 'required_if:order_type,delivery',
            'latitude' => 'required_if:order_type,delivery',
            'dm_tips' => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            info("order validation failed");
            info(json_encode(Helpers::error_processor($validator)));
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        //info("order validation success");

        if ($request->payment_method == 'wallet' && Helpers::get_business_settings('wallet_status', false) != 1) {
            return response()->json([
                'errors' => [
                    ['code' => 'payment_method', 'message' => trans('messages.customer_wallet_disable_warning')]
                ]
            ], 203);
        }
        $coupon = null;
        $delivery_charge = $request->delivery_charge ? $request->delivery_charge : null;
        $free_delivery_by = null;
        $awt_schedule_at = $request->schedule_at;
        $schedule_at = $request->schedule_at ? \Carbon\Carbon::parse($request->schedule_at) : now();
        if ($request->schedule_at && $schedule_at < now()) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_time', 'message' => trans('messages.you_can_not_schedule_a_order_in_past')]
                ]
            ], 406);
        }
        $restaurant = Restaurant::with('discount')->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = ' . $schedule_at->format('w') . ' and `restaurant_schedule`.`opening_time` < "' . $schedule_at->format('H:i:s') . '" and `restaurant_schedule`.`closing_time` >"' . $schedule_at->format('H:i:s') . '") > 0), true, false) as open')->where('id', $request->restaurant_id)->first();

        if (!$restaurant) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_time', 'message' => trans('messages.restaurant_not_found')]
                ]
            ], 404);
        }

        if ($request->schedule_at && !$restaurant->schedule_order) {
            return response()->json([
                'errors' => [
                    ['code' => 'schedule_at', 'message' => trans('messages.schedule_order_not_available')]
                ]
            ], 406);
        }

        if ($restaurant->open == false) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_time', 'message' => trans('messages.restaurant_is_closed_at_order_time')]
                ]
            ], 406);
        }

        if ($request['coupon_code']) {
            $custArr[] = $request->restaurant_id;
            $strCustArr = json_encode($custArr);
            $coupon = Coupon::active()->where(['code' => $request['coupon_code'], 'data' => $strCustArr])->first();
            if (isset($coupon)) {
                $staus = CouponLogic::is_valide($coupon, $request->user()->id, $request['restaurant_id']);
                if ($staus == 407) {
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => trans('messages.coupon_expire')]
                        ]
                    ], 407);
                } else if ($staus == 406) {
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => trans('messages.coupon_usage_limit_over')]
                        ]
                    ], 406);
                } else if ($staus == 404) {
                    return response()->json([
                        'errors' => [
                            ['code' => 'coupon', 'message' => trans('messages.not_found')]
                        ]
                    ], 404);
                }
                if ($coupon->coupon_type == 'free_delivery') {
                    $delivery_charge = 0;
                    $coupon = null;
                    $free_delivery_by = 'admin';
                }
            } else {
                return response()->json([
                    'errors' => [
                        ['code' => 'coupon', 'message' => trans('messages.not_found')]
                    ]
                ], 401);
            }
        }

        // if($request->latitude >= 13 && $request->latitude < 14){
        // $per_km_shipping_charge = 0;
        // $minimum_shipping_charge = 0;
        // }else if($request->latitude >= 15 && $request->latitude < 16){
        // $per_km_shipping_charge = 0;
        // $minimum_shipping_charge = 0;
        // }else{
        // $per_km_shipping_charge = (float)BusinessSetting::where(['key' => 'per_km_shipping_charge'])->first()->value;
        // $minimum_shipping_charge = (float)BusinessSetting::where(['key' => 'minimum_shipping_charge'])->first()->value;
        // }



        if ($request->latitude && $request->longitude) {
            $point = new Point($request->latitude, $request->longitude);
            $zone = Zone::where('id', $restaurant->zone_id)->contains('coordinates', $point)->first();
            if (!$zone) {
                $errors = [];
                array_push($errors, ['code' => 'coordinates', 'message' => trans('messages.out_of_coverage')]);
                return response()->json([
                    'errors' => $errors
                ], 403);
            }
            if ($zone->per_km_shipping_charge >= 0 && $zone->minimum_shipping_charge >= 0) {
                $per_km_shipping_charge = $zone->per_km_shipping_charge;
                $minimum_shipping_charge = $zone->minimum_shipping_charge;
            }
        }

        if ($request['order_type'] != 'take_away' && !$restaurant->free_delivery && !isset($delivery_charge)) {
            if ($restaurant->self_delivery_system) {
                $per_km_shipping_charge = $restaurant->per_km_shipping_charge;
                $minimum_shipping_charge = $restaurant->minimum_shipping_charge;
            }
        }

        if ($request->delivery_charge) {
            $original_delivery_charge = $request->delivery_charge;
        } else {
            $distance_shipping_charge = $request->distance * $per_km_shipping_charge;
            $calculated_charge = max($distance_shipping_charge, $minimum_shipping_charge);
            $original_delivery_charge = $calculated_charge;
        }

        if ($request['order_type'] == 'take_away') {
            $per_km_shipping_charge = 0;
            $minimum_shipping_charge = 0;
        }
        if (!isset($delivery_charge)) {
            $delivery_charge = ($request->distance * $per_km_shipping_charge > $minimum_shipping_charge) ? $request->distance * $per_km_shipping_charge : $minimum_shipping_charge;
        }


        $address = [
            'contact_person_name' => $request->contact_person_name ? $request->contact_person_name : $request->user()->f_name . ' ' . $request->user()->f_name,
            'contact_person_number' => $request->contact_person_number ? $request->contact_person_number : $request->user()->phone,
            'address_type' => $request->address_type ? $request->address_type : 'Delivery',
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'longitude' => (string) $request->longitude,
            'latitude' => (string) $request->latitude,
        ];

        /*  $address = [
        'contact_person_name' => "Demo Customer",
        'contact_person_number' => "1111111111",
        'address_type' => "home",
        'address' => "test order address",
        'floor' => "test order floor",
        'road' => "test order road",
        'house' => "test order home",
        'longitude' => "82.85730799999999",
        'latitude' => " 17.54723469999999",
        ];*/



        $total_addon_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;

        $order_details = [];
        $order = new Order();
        $order->id = 100000 + Order::all()->count() + 1;
        if (Order::find($order->id)) {
            $order->id = Order::orderBy('id', 'desc')->first()->id + 1;
        }

        $order->user_id = $request->user()->id;
        $order->order_amount = $request['order_amount'];

        $order->payment_status = $request['payment_method'] == 'wallet' ? 'paid' : 'unpaid';
        $order->order_status = $request['payment_method'] == 'digital_payment' ? 'failed' : ($request->payment_method == 'wallet' ? 'confirmed' : 'pending');
        $order->coupon_code = $request['coupon_code'];
        $order->payment_method = $request->payment_method;
        $order->transaction_reference = null;
        $order->order_note = $request['order_note'];
        $order->awt_delivery_notes = $request['cust_delivery_note'];
        $order->order_type = $request['order_type'];
        $order->restaurant_id = $request['restaurant_id'];
        $order->delivery_charge = round($delivery_charge, config('round_up_to_digit')) ?? 0;
        $order->original_delivery_charge = round($original_delivery_charge, config('round_up_to_digit'));
        $order->delivery_address = json_encode($address);
        $order->schedule_at = $schedule_at;
        $order->scheduled = $request->schedule_at ? 1 : 0;
        if ($request->awt_package_charges != NULL) {
            $order->awt_order_pckg_charge = $request->awt_package_charges;
        } else {
            $order->awt_order_pckg_charge = 0;
        }

        if ($order->schedule_at != NULL && $order->scheduled == 1) {
            $order->cust_order_status = "preorder";
            $order->cust_preorder = now();
            $order->awt_schedule_time = date("d-m-Y h:i", strtotime($schedule_at));
        } else {
            $order->cust_order_status = "pending";
            $order->awt_schedule_time = "n/a";
        }

        if ($request->payment_method == "razor_pay") {
            $order->order_status = "pending";
            $order->payment_status = 'paid';
            $order->transaction_reference = $request['razorpay_payment_id'];
        }

        $order->otp = rand(1000, 9999);
        $order->zone_id = $restaurant->zone_id;
        $dm_tips_manage_status = BusinessSetting::where('key', 'dm_tips_status')->first()->value;
        if ($dm_tips_manage_status == 1) {
            $order->dm_tips = $request->dm_tips ?? 0;
        } else {
            $order->dm_tips = 0;
        }
        $order->pending = now();
        $order->confirmed = $request->payment_method == 'wallet' ? now() : null;
        $order->created_at = now();
        $order->updated_at = now();
        //   $request['cart'] = '[{"food_id":"5","item_campaign_id":null,"price":"141","variant":"","variation":[],"quantity":"1","add_on_ids":[],"add_ons":[],"add_on_qtys":[]}]';
        $awtCartDetails = $request['cart'];
        $req_cart = json_decode($request['cart'], true);
        //dd($req_cart);exit;
        foreach ($req_cart as $c) {
            if ($c['item_campaign_id'] != null) {
                $product = ItemCampaign::active()->find($c['item_campaign_id']);
                if ($product) {
                    if (count(json_decode($product['variations'], true)) > 0) {
                        $price = Helpers::variation_price($product, json_encode($c['variation']));
                    } else {
                        $price = $product['price'];
                    }
                    $product->tax = $restaurant->tax;
                    $product = Helpers::product_data_formatting($product, false, false, app()->getLocale());
                    $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id', $c['add_on_ids'])->get(), $c['add_on_qtys']);
                    $product["awt_food_qty"] = $c['quantity'];
                    // dd($product);exit;
                    //   $newArray=array("awt_food_qty"=>7);
                    //  $product1 = array_merge($product,$newArray);

                    $var_off_prod_type = $c['awt_offer_product_type'];
                    $var_off_str = $c['awt_buy_N_get_n'];

                    $mysqli = mysqli_connect("localhost", "manicmoon", "Vizag@4532", "foodrsiv_newfrdb");
                    $qry = "SELECT discount_buy_id, discount_title, city_id, rest_id, offer_cat_id, offer_menu_id, min_qty, offer_qty, applic_cat_id, applic_menu_id FROM awt_dis_buy_tbl WHERE applic_menu_id=" . $c['food_id'];
                    $rslt = mysqli_query($mysqli, $qry);
                    $recCount = mysqli_num_rows($rslt);
                    // info("Qry - ".$qry);
                    // info("Record Count Offer - ".$recCount);
                    if ($recCount > 0) {
                        $price = 0;
                    }

                    $or_d = [
                        'food_id' => null,
                        'item_campaign_id' => $c['item_campaign_id'],
                        // 'food_details' => json_encode($product1),
                        'quantity' => $c['quantity'],
                        'price' => $price,
                        'tax_amount' => Helpers::tax_calculate($product, $price),
                        'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
                        'discount_type' => 'discount_on_product',
                        'variant' => json_encode($c['variant']),
                        'variation' => json_encode($c['variation']),
                        'add_ons' => json_encode($addon_data['addons']),
                        'total_add_on_price' => $addon_data['total_add_on_price'],
                        'awt_offer_food_str' => $var_off_str,
                        'awt_offer_food_type' => $var_off_prod_type,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $order_details[] = $or_d;
                    $total_addon_price += $or_d['total_add_on_price'];
                    $product_price += $price * $or_d['quantity'];
                    $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'];
                } else {
                    return response()->json([
                        'errors' => [
                            ['code' => 'campaign', 'message' => trans('messages.product_unavailable_warning')]
                        ]
                    ], 401);
                }
            } else {
                $product = Food::active()->find($c['food_id']);
                if ($product) {
                    if (count(json_decode($product['variations'], true)) > 0) {
                        $price = Helpers::variation_price($product, json_encode($c['variation']));
                    } else {
                        $price = $product['price'];
                    }
                    $product->tax = $restaurant->tax;
                    $product = Helpers::product_data_formatting($product, false, false, app()->getLocale());
                    $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id', $c['add_on_ids'])->get(), $c['add_on_qtys']);
                    $product["awt_food_qty"] = $c['quantity'];

                    $var_off_prod_type = $c['awt_offer_product_type'];
                    $var_off_str = $c['awt_buy_N_get_n'];

                    $mysqli = mysqli_connect("localhost", "manicmoon", "Vizag@4532", "foodrsiv_newfrdb");
                    $qry = "SELECT discount_buy_id, discount_title, city_id, rest_id, offer_cat_id, offer_menu_id, min_qty, offer_qty, applic_cat_id, applic_menu_id FROM awt_dis_buy_tbl WHERE applic_menu_id=" . $c['food_id'];
                    $rslt = mysqli_query($mysqli, $qry);
                    $recCount = mysqli_num_rows($rslt);
                    // info("Qry - ".$qry);
                    // info("Record Count Offer - ".$recCount);
                    if ($recCount > 0) {
                        $price = 0;
                    }

                    $or_d = [
                        'food_id' => $c['food_id'],
                        'item_campaign_id' => null,
                        'food_details' => json_encode($product),
                        'quantity' => $c['quantity'],
                        'price' => round($price, config('round_up_to_digit')),
                        'tax_amount' => round(Helpers::tax_calculate($product, $price), config('round_up_to_digit')),
                        'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
                        'discount_type' => 'discount_on_product',
                        'variant' => json_encode($c['variant']),
                        'variation' => json_encode($c['variation']),
                        'add_ons' => json_encode($addon_data['addons']),
                        'total_add_on_price' => round($addon_data['total_add_on_price'], config('round_up_to_digit')),
                        'awt_offer_food_str' => $var_off_str,
                        'awt_offer_food_type' => $var_off_prod_type,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $total_addon_price += $or_d['total_add_on_price'];
                    $product_price += $price * $or_d['quantity'];
                    $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'];
                    $order_details[] = $or_d;
                } else {
                    return response()->json([
                        'errors' => [
                            ['code' => 'food', 'message' => trans('messages.product_unavailable_warning')]
                        ]
                    ], 401);
                }
            }

        }
        $restaurant_discount = Helpers::get_restaurant_discount($restaurant);
        if (isset($restaurant_discount)) {
            if ($product_price + $total_addon_price < $restaurant_discount['min_purchase']) {
                $restaurant_discount_amount = 0;
            }

            if ($restaurant_discount_amount > $restaurant_discount['max_discount']) {
                $restaurant_discount_amount = $restaurant_discount['max_discount'];
            }
        }
        $coupon_discount_amount = $coupon ? CouponLogic::get_discount($coupon, $product_price + $total_addon_price - $restaurant_discount_amount) : 0;
        $total_price = $product_price + $total_addon_price - $restaurant_discount_amount - $coupon_discount_amount;

        $tax = $restaurant->tax;
        $total_tax_amount = ($tax > 0) ? (($total_price * $tax) / 100) : 0;

        if ($restaurant->minimum_order > $product_price + $total_addon_price) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_time', 'message' => trans('messages.you_need_to_order_at_least', ['amount' => $restaurant->minimum_order . ' ' . Helpers::currency_code()])]
                ]
            ], 406);
        }

        $free_delivery_over = BusinessSetting::where('key', 'free_delivery_over')->first()->value;
        if (isset($free_delivery_over)) {
            if ($free_delivery_over <= $product_price + $total_addon_price - $coupon_discount_amount - $restaurant_discount_amount) {
                $order->delivery_charge = 0;
                $free_delivery_by = 'admin';
            }
        }

        if ($restaurant->free_delivery) {
            $order->delivery_charge = 0;
            $free_delivery_by = 'vendor';
        }

        if ($coupon) {
            $coupon->increment('total_uses');
        }
        $awt_package_charges = 0;
        if ($request->awt_package_charges != NULL) {
            $awt_package_charges = $request->awt_package_charges;
        } else {
            $awt_package_charges = 0;
        }

        $order_amount = round($total_price + $total_tax_amount + $awt_package_charges + $order->delivery_charge, config('round_up_to_digit'));

        //   $order_amount = round($total_price + $total_tax_amount + $order->delivery_charge , config('round_up_to_digit'));

        if ($request->payment_method == 'wallet' && $request->user()->wallet_balance < $order_amount) {
            return response()->json([
                'errors' => [
                    ['code' => 'order_amount', 'message' => trans('messages.insufficient_balance')]
                ]
            ], 203);
        }
        // dd($order->dm_tips);
        try {
            $order->coupon_discount_amount = round($coupon_discount_amount, config('round_up_to_digit'));
            $order->coupon_discount_title = $coupon ? $coupon->title : '';
            $order->free_delivery_by = $free_delivery_by;
            $order->restaurant_discount_amount = round($restaurant_discount_amount, config('round_up_to_digit'));
            $order->total_tax_amount = round($total_tax_amount, config('round_up_to_digit'));
            // $order->order_amount = $order_amount + $order->dm_tips;
            $order->order_amount = $order_amount;
            $order->awtOrderCartDetails = $awtCartDetails;
            $order->save();



            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;
            }
            OrderDetail::insert($order_details);
            Helpers::send_order_notification($order);

            $customer = $request->user();
            $customer->zone_id = $restaurant->zone_id;
            $customer->save();
            // dd($customer);
            //exit;

            $restaurant->increment('total_order');
            if ($request->payment_method == 'wallet')
                CustomerLogic::create_wallet_transaction($order->user_id, $order->order_amount, 'order_place', $order->id);

            try {
                if ($order->order_status == 'pending') {
                    Mail::to($customer['email'])->send(new \App\Mail\OrderPlaced($order->id));
                }
            } catch (\Exception $ex) {
                info($ex);
            }
            info("New Order Created Log --> end");
            info($order->id);
            return response()->json([
                'message' => trans('messages.order_placed_successfully'),
                'order_id' => $order->id,
                'total_ammount' => $total_price + $order->delivery_charge + $total_tax_amount
            ], 200);
        } catch (\Exception $e) {
            info("error awt_place_order method");
            info($e);
            return response()->json([$e], 403);
        }

        // return response()->json([
        //     'errors' => [
        //         ['code' => 'order_time', 'message' => trans('messages.failed_to_place_order')]
        //     ]
        // ], 403);
    }

    public function awt_place_order(Request $request)
    {

        //  info("awtOrderAmount - ".$request->order_amount);
        //   info("awtPckageCharges -".$request->awt_package_charges);

        //info("called awt_place_order method");
        //info("Post  data ");
        info(json_encode($request->all()));
        $validator = Validator::make($request->all(), [
            'order_amount' => 'required',
            'payment_method' => 'required|in:cash_on_delivery,digital_payment,razor_pay,wallet',
            'order_type' => 'required|in:take_away,delivery',
            'restaurant_id' => 'required',
            'distance' => 'required_if:order_type,delivery',
            'address' => 'required_if:order_type,delivery',
            'longitude' => 'required_if:order_type,delivery',
            'latitude' => 'required_if:order_type,delivery',
            'dm_tips' => 'nullable|numeric'
        ]);

        // if ($validator->fails()) {
        //     info("order validation failed");
        //     info(json_encode(Helpers::error_processor($validator)));
        //     return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        // }

        if ($validator->fails()) {
            info("order validation failed");
            return array("status" => "invalid", "message" => $validator->errors()->first());
        }

        info("order validation success");

        if ($request->payment_method == 'wallet' && Helpers::get_business_settings('wallet_status', false) != 1) {
            return array("status" => "invalid", "message" => trans('messages.customer_wallet_disable_warning'));
        }
        if ($request->order_amount == '0.0' || $request->order_amount == 0) {
            return array("status" => "invalid", "message" => "The Order Amount must be greater than 0, here we are getting....." . $request->order_amount);
        }
        if ($request->order_amount > 500 && $request->payment_method == 'cash_on_delivery') {
            return array("status" => "invalid", "message" => "Not eligible for COD as the amount exceeds ₹500.");
        }
        $coupon = null;
        $delivery_charge = $request->delivery_charge ? $request->delivery_charge : null;
        $free_delivery_by = null;
        $awt_schedule_at = $request->schedule_at;
        $schedule_at = $request->schedule_at ? Carbon::parse($request->schedule_at) : now();
        if ($request->schedule_at && $schedule_at < now()) {
            return array("status" => "invalid", "message" => trans('messages.you_can_not_schedule_a_order_in_past'));
        }
        $restaurant = Restaurant::with('discount')->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = ' . $schedule_at->format('w') . ' and `restaurant_schedule`.`opening_time` < "' . $schedule_at->format('H:i:s') . '" and `restaurant_schedule`.`closing_time` >"' . $schedule_at->format('H:i:s') . '") > 0), true, false) as open')->where('id', $request->restaurant_id)->first();

        if (!$restaurant) {
            return array("status" => "invalid", "message" => trans('messages.restaurant_not_found'));
        }

        if ($request->schedule_at && !$restaurant->schedule_order) {
            return array("status" => "invalid", "message" => trans('messages.schedule_order_not_available'));
        }

        if ($restaurant->open == false) {
            return array("status" => "invalid", "message" => trans('messages.restaurant_is_closed_at_order_time'));
        }

        if ($request['coupon_code']) {
            info("Coupon Code Exist");
            $custArr[] = (string) $request->restaurant_id;
            $strCustArr = json_encode($custArr);
            $coupon = Coupon::active()->where(['code' => $request['coupon_code'], 'data' => $strCustArr])->first();
            info('coupon data inside try is....' . $coupon);
            if (!$coupon) {
                info('if coupon not exists.... ');
                $zone_id = Restaurant::where('id', $request->restaurant_id)->value('zone_id');
                info('zone id is....' . $zone_id);

                $zoneArr = [$zone_id]; // Create an array with the zone_id

                $coupons = Coupon::active()
                    ->where('code', $request['coupon_code'])
                    ->get(); // Retrieve all matching coupons

                $matchingCoupons = $coupons->filter(function ($coupon) use ($zoneArr) {
                    $data = json_decode($coupon->data, true); // Decode the JSON data from the database

                    // Check if $zoneArr value exists in the decoded $data array
                    return in_array($zoneArr[0], $data);
                });

                $coupon = $matchingCoupons->first();
                info('if zone wise.......coupon data inside try is....' . $coupon);
            }
            if (isset($coupon)) {
                $staus = CouponLogic::is_valide($coupon, $request->user()->id, $request['restaurant_id']);
                if ($staus == 407) {
                    return array("status" => "invalid", "message" => trans('messages.coupon_expire'));
                } else if ($staus == 406) {
                    return array("status" => "invalid", "message" => trans('messages.coupon_usage_limit_over'));
                } else if ($staus == 404) {
                    return array("status" => "invalid", "message" => trans('messages.not_found'));
                }
                if ($coupon->coupon_type == 'free_delivery') {
                    $delivery_charge = 0;
                    $coupon = null;
                    $free_delivery_by = 'admin';
                }
            } else {
                return array("status" => "invalid", "message" => trans('messages.not_found'));
            }
        }

        // if($request->latitude >= 13 && $request->latitude < 14){
        // $per_km_shipping_charge = 0;
        // $minimum_shipping_charge = 0;
        // }else if($request->latitude >= 15 && $request->latitude < 16){
        // $per_km_shipping_charge = 0;
        // $minimum_shipping_charge = 0;
        // }else{
        // $per_km_shipping_charge = (float)BusinessSetting::where(['key' => 'per_km_shipping_charge'])->first()->value;
        // $minimum_shipping_charge = (float)BusinessSetting::where(['key' => 'minimum_shipping_charge'])->first()->value;
        // }



        if ($request->latitude && $request->longitude) {
            info("Lat and Longs Success");
            $point = new Point($request->latitude, $request->longitude);
            $zone = Zone::where('id', $restaurant->zone_id)->contains('coordinates', $point)->first();
            if (!$zone) {
                // $errors = [];
                // array_push($errors, ['code' => 'coordinates', 'message' => trans('messages.out_of_coverage')]);
                // return response()->json([
                //     'errors' => $errors
                // ], 403);
                return array("status" => "invalid", "message" => trans('messages.out_of_coverage'));
            }
            if ($zone->per_km_shipping_charge >= 0 && $zone->minimum_shipping_charge >= 0) {
                $per_km_shipping_charge = $zone->per_km_shipping_charge;
                $minimum_shipping_charge = $zone->minimum_shipping_charge;
            }
        }

        if ($request['order_type'] != 'take_away' && !$restaurant->free_delivery && !isset($delivery_charge)) {
            info("request['order_type'] != 'take_away' && !restaurant->free_delivery && !isset(delivery_charge)");
            if ($restaurant->self_delivery_system) {
                $per_km_shipping_charge = $restaurant->per_km_shipping_charge;
                $minimum_shipping_charge = $restaurant->minimum_shipping_charge;
            }
        }

        if ($request->delivery_charge) {
            info("request->delivery_charge");
            $original_delivery_charge = $request->delivery_charge;
        } else {
            info("request->delivery_charge else condition");
            $distance_shipping_charge = $request->distance * $per_km_shipping_charge;
            $calculated_charge = max($distance_shipping_charge, $minimum_shipping_charge);
            $original_delivery_charge = $calculated_charge;
        }

        if ($request['order_type'] == 'take_away') {
            info("request['order_type'] == 'take_away'");
            $per_km_shipping_charge = 0;
            $minimum_shipping_charge = 0;
        }
        if (!isset($delivery_charge)) {
            info("!isset(delivery_charge)");
            $delivery_charge = ($request->distance * $per_km_shipping_charge > $minimum_shipping_charge) ? $request->distance * $per_km_shipping_charge : $minimum_shipping_charge;
        }


        $address = [
            'contact_person_name' => $request->contact_person_name ? $request->contact_person_name : $request->user()->f_name . ' ' . $request->user()->l_name,
            'contact_person_number' => $request->contact_person_number ? $request->contact_person_number : $request->user()->phone,
            'address_type' => $request->address_type ? $request->address_type : 'Delivery',
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'longitude' => (string) $request->longitude,
            'latitude' => (string) $request->latitude,
        ];

        /*  $address = [
        'contact_person_name' => "Demo Customer",
        'contact_person_number' => "1111111111",
        'address_type' => "home",
        'address' => "test order address",
        'floor' => "test order floor",
        'road' => "test order road",
        'house' => "test order home",
        'longitude' => "82.85730799999999",
        'latitude' => " 17.54723469999999",
        ];*/



        $total_addon_price = 0;
        $total_options_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;
        $modified_tax = 0;

        $order_details = [];
        $order = new Order();
        $order->id = 100000 + Order::all()->count() + 1;
        if (Order::find($order->id)) {
            $order->id = Order::orderBy('id', 'desc')->first()->id + 1;
        }

        $order->user_id = $request->user()->id;
        $order->order_amount = $request['order_amount'];

        $order->payment_status = $request['payment_method'] == 'wallet' ? 'paid' : 'unpaid';
        $order->order_status = $request['payment_method'] == 'digital_payment' ? 'failed' : ($request->payment_method == 'wallet' ? 'pending' : 'pending');
        $order->coupon_code = $request['coupon_code'];
        $order->payment_method = $request->payment_method;
        $order->transaction_reference = null;
        $order->order_note = $request['order_note'];
        $order->awt_delivery_notes = $request['cust_delivery_note'];
        $order->order_type = $request['order_type'];
        $order->restaurant_id = $request['restaurant_id'];
        $order->delivery_charge = round($delivery_charge, config('round_up_to_digit')) ?? 0;
        $order->awt_dm_base_fare = $request['awt_dm_base_fare'];
        $order->awt_dm_extra_fare = $request['awt_dm_extra_fare'];
        $order->original_delivery_charge = round($original_delivery_charge, config('round_up_to_digit'));
        $order->delivery_address = json_encode($address);
        $order->schedule_at = $schedule_at;
        $order->scheduled = $request->schedule_at ? 1 : 0;
        if ($request->awt_package_charges != NULL) {
            $order->awt_order_pckg_charge = $request->awt_package_charges;
        } else {
            $order->awt_order_pckg_charge = 0;
        }

        if ($order->schedule_at != NULL && $order->scheduled == 1) {
            $order->cust_order_status = "preorder";
            $order->cust_preorder = now();
            $order->awt_schedule_time = date("d-m-Y h:i", strtotime($schedule_at));
        } else {
            $order->cust_order_status = "pending";
            $order->awt_schedule_time = "n/a";
        }

        if ($request->payment_method == "razor_pay") {
            $order->order_status = "pending";
            $order->payment_status = 'paid';
            $order->transaction_reference = $request['razorpay_payment_id'];
        }

        $order->otp = rand(1000, 9999);
        $order->zone_id = $restaurant->zone_id;
        $dm_tips_manage_status = BusinessSetting::where('key', 'dm_tips_status')->first()->value;
        if ($dm_tips_manage_status == 1) {
            $order->dm_tips = $request->dm_tips ?? 0;
        } else {
            $order->dm_tips = 0;
        }
        $order->pending = now();
        // $order->confirmed = $request->payment_method == 'wallet' ? now() : null;
        $order->created_at = now();
        $order->updated_at = now();
        //   $request['cart'] = '[{"food_id":"5","item_campaign_id":null,"price":"141","variant":"","variation":[],"quantity":"1","add_on_ids":[],"add_ons":[],"add_on_qtys":[]}]';
        $awtCartDetails = $request['cart'];
        $req_cart = json_decode($request['cart'], true);
        //dd($req_cart);exit;
        // return $req_cart;
        foreach ($req_cart as $c) {
            info("req_cart foreach loop entered...." . print_r($c, true));
            // if ($c['item_campaign_id'] != null) {
            //     $product = ItemCampaign::active()->find($c['item_campaign_id']);
            //     if ($product) {
            //         if (count(json_decode($product['variations'], true)) > 0) {
            //             $price = Helpers::variation_price($product, json_encode($c['variation']));
            //         } else {
            //             $price = $product['price'];
            //         }
            //         $product->tax = $restaurant->tax;
            //         $product = Helpers::product_data_formatting($product, false, false, app()->getLocale());
            //         $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id', $c['add_on_ids'])->get(), $c['add_on_qtys']);
            //         $product["awt_food_qty"] = $c['quantity'];
            //         // dd($product);exit;
            //         //   $newArray=array("awt_food_qty"=>7);
            //         //  $product1 = array_merge($product,$newArray);
            //         $or_d = [
            //             'food_id' => null,
            //             'item_campaign_id' => $c['item_campaign_id'],
            //             // 'food_details' => json_encode($product1),
            //             'quantity' => $c['quantity'],
            //             'price' => $price,
            //             'tax_amount' => Helpers::tax_calculate($product, $price),
            //             'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
            //             'discount_type' => 'discount_on_product',
            //             'variant' => json_encode($c['variant']),
            //             'variation' => json_encode($c['variation']),
            //             'add_ons' => json_encode($addon_data['addons']),
            //             'total_add_on_price' => $addon_data['total_add_on_price'],
            //             'created_at' => now(),
            //             'updated_at' => now()
            //         ];
            //         $order_details[] = $or_d;
            //         $total_addon_price += $or_d['total_add_on_price'];
            //         $product_price += $price * $or_d['quantity'];
            //         $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'];
            //     } else {
            //         return array("status" => "invalid", "message" => trans('messages.product_unavailable_warning'));
            //         // return response()->json([
            //         //     'errors' => [
            //         //         ['code' => 'campaign', 'message' => trans('messages.product_unavailable_warning')]
            //         //     ]
            //         // ], 401);
            //     }
            // } else {
            $product = Food::active()->find($c['food_id']);
            if ($product) {
                if (count(json_decode($product['variations'], true)) > 0) {
                    // if (count($c['variations']) > 0) {
                    //     $price = Helpers::variation_price($product, json_encode($c['variations']));
                    // } else {
                    //     $price = $product['price'];
                    // }
                    $price = $c['price'];
                } else {
                    $price = $c['price'];
                }

                if ($c['option_price'] > 0) {
                    $c['price'] = $c['option_price'];
                }

                $product->tax = $restaurant->tax;
                $product = Helpers::product_data_formatting($product, false, false, app()->getLocale());
                $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id', json_decode($c['add_ons']))->get(), json_decode($c['add_on_qtys']));
                $options_data = Helpers::calculate_options_price($c['options'], $c['option_qtys']);
                $product["awt_food_qty"] = $c['quantity'];
                $base_price = $options_data ? $options_data['total_options_price'] : $product->price;
                $or_d = [
                    'food_id' => $c['food_id'],
                    'item_campaign_id' => null,
                    'food_details' => json_encode($product),
                    'quantity' => $c['quantity'],
                    'price' => round($price / $c['quantity'], config('round_up_to_digit')),
                    // 'tax_amount' => round(Helpers::tax_calculate($product, $price), config('round_up_to_digit')),
                    'tax_amount' => round(Helpers::tax_calculate($product, round($price / $c['quantity'], config('round_up_to_digit'))), config('round_up_to_digit')),
                    // 'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
                    'discount_on_food' => Helpers::product_discount_calculate($product, $base_price, $restaurant),
                    'discount_type' => 'discount_on_product',
                    'variant' => "",
                    'variation' => json_encode([]),
                    'add_ons' => json_encode($addon_data['addons']),
                    'total_add_on_price' => round($addon_data['total_add_on_price'] * $c['quantity'], config('round_up_to_digit')),
                    'options' => $options_data ? json_encode($options_data['options']) : '[]',
                    'total_options_price' => $options_data ? $options_data['total_options_price'] : 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
                $total_addon_price += $or_d['total_add_on_price'];
                $total_options_price += $or_d['total_options_price'];
                $product_price += $or_d['price'] * $or_d['quantity'];
                $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'];
                $order_details[] = $or_d;
                if ($request->tax_amount) {
                    $modified_tax = $request->tax_amount;
                } else {
                    $modified_tax += Helpers::tax_calculate($product, $price);
                }
            } else {
                return array("status" => "invalid", "message" => trans('messages.product_unavailable_warning'));
                // return response()->json([
                //     'errors' => [
                //         ['code' => 'food', 'message' => trans('messages.product_unavailable_warning')]
                //     ]
                // ], 401);
            }
            // }

        }
        $restaurant_discount = Helpers::get_restaurant_discount($restaurant);
        if (isset($restaurant_discount)) {
            info("isset(restaurant_discount)");
            if ($product_price < $restaurant_discount['min_purchase']) {
                $restaurant_discount_amount = 0;
            }

            if ($restaurant_discount_amount > $restaurant_discount['max_discount']) {
                $restaurant_discount_amount = $restaurant_discount['max_discount'];
            }
        }
        $coupon_discount_amount = $coupon ? CouponLogic::get_discount($coupon, $product_price - $restaurant_discount_amount) : 0;
        $total_price = $product_price - $restaurant_discount_amount - $coupon_discount_amount;

        $tax = $restaurant->tax;
        $total_tax_amount = $modified_tax > 0 ? $modified_tax : (($tax > 0) ? (($total_price * $tax) / 100) : 0);

        if ($restaurant->minimum_order > $product_price) {
            return array("status" => "invalid", "message" => trans('messages.you_need_to_order_at_least', ['amount' => $restaurant->minimum_order . ' ' . Helpers::currency_code()]));
            // return response()->json([
            //     'errors' => [
            //         ['code' => 'order_time', 'message' => trans('messages.you_need_to_order_at_least', ['amount' => $restaurant->minimum_order . ' ' . Helpers::currency_code()])]
            //     ]
            // ], 406);
        }

        $free_delivery_over = BusinessSetting::where('key', 'free_delivery_over')->first()->value;
        if (isset($free_delivery_over)) {
            info("isset(free_delivery_over)");
            if ($free_delivery_over <= $product_price - $coupon_discount_amount - $restaurant_discount_amount) {
                $order->delivery_charge = 0;
                $free_delivery_by = 'admin';
            }
        }

        if ($restaurant->free_delivery) {
            $order->delivery_charge = 0;
            $free_delivery_by = 'vendor';
        }

        if ($coupon) {
            $coupon->increment('total_uses');
        }

        if ($request->awt_package_charges != NULL) {
            $awt_package_charges = $request->awt_package_charges;
        } else {
            $awt_package_charges = 0;
        }

        // $order_amount = round($total_price + $total_tax_amount + $awt_package_charges + $order->delivery_charge, config('round_up_to_digit'));
        $order_amount = $request->order_amount;
        //   $order_amount = round($total_price + $total_tax_amount + $order->delivery_charge , config('round_up_to_digit'));

        if ($request->payment_method == 'wallet' && $request->user()->wallet_balance < $order_amount) {
            return array("status" => "invalid", "message" => trans('messages.insufficient_balance'));
            // return response()->json([
            //     'errors' => [
            //         ['code' => 'order_amount', 'message' => trans('messages.insufficient_balance')]
            //     ]
            // ], 203);
        }
        // dd($order->dm_tips);
        try {
            info("Inside the try");
            $order->coupon_discount_amount = round($coupon_discount_amount, config('round_up_to_digit'));
            $order->coupon_discount_title = $coupon ? $coupon->title : '';
            $order->free_delivery_by = $free_delivery_by;
            $order->restaurant_discount_amount = round($restaurant_discount_amount, config('round_up_to_digit'));
            $order->total_tax_amount = round($total_tax_amount, config('round_up_to_digit'));
            // $order->order_amount = $order_amount + $order->dm_tips;
            $order->order_amount = $order_amount;
            $order->awtOrderCartDetails = $awtCartDetails;
            if ($request->wallet_amount != '' && $request->wallet_amount != 0) {
                $order->wallet_amount = $request->wallet_amount;
            }
            if ($request->payment_method == 'wallet') {
                // $order->wallet_amount = $order_amount + $order->dm_tips + $restaurant_discount_amount;
                $order->wallet_amount = $order_amount;
            }
            $order->save();
            if ($request->wallet_amount != '' && $request->wallet_amount != 0) {
                CustomerLogic::create_wallet_transaction($order->user_id, $request->wallet_amount, 'order_place', $order->id);
            }
            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;
            }
            OrderDetail::insert($order_details);
            Helpers::send_order_notification($order);
            Helpers::petPoojaSaveOrder($order->id);

            $customer = $request->user();
            $customer->zone_id = $restaurant->zone_id;
            $customer->save();
            // dd($customer);
            //exit;

            $restaurant->increment('total_order');
            if ($request->payment_method == 'wallet')
                CustomerLogic::create_wallet_transaction($order->user_id, $order->order_amount, 'order_place', $order->id);

            try {
                if ($order->order_status == 'pending') {
                    Mail::to($customer['email'])->send(new \App\Mail\OrderPlaced($order->id));
                }
            } catch (\Exception $ex) {
                info($ex);
            }

            try {
                if ($order->order_status == 'pending' || $order->order_status == 'confirmed') {
                    $mobile = $customer['phone'];
                    $message = "Dear " . $customer['f_name'] . "

Order has been placed @ " . date('d M Y, h:i A', strtotime($order->schedule_at)) . "- for store " . $restaurant['name'] . " Order ID: " . $order->id . "

Thanks and Regards
Food Ride";
                    $template_id = 1407169268569030169;
                    Helpers::send_cmoon_sms($mobile, $message, $template_id);
                }
            } catch (\Exception $ex) {
                info($ex);
            }
            info("New Order Created Log --> end");
            info($order->id);
            $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order->id, $order->user_id, 'pending');
            return response()->json([
                "status" => "valid",
                'message' => trans('messages.order_placed_successfully'),
                'order_id' => $order->id,
                'total_ammount' => $total_price + $order->delivery_charge + $total_tax_amount
            ], 200);
        } catch (\Exception $e) {
            info("error awt_place_order method");
            info($e);
            return response()->json([$e], 403);
        }

        // return array("status" => "invalid", "message" => trans('messages.failed_to_place_order'));
        // return response()->json([
        //     'errors' => [
        //         ['code' => 'order_time', 'message' => trans('messages.failed_to_place_order')]
        //     ]
        // ], 403);
    }

    public function ongoing_orders(Request $request)
    {

        $orders = Order::where(['user_id' => $request->user()->id])
            ->whereNotIn('order_status', ['delivered', 'canceled', 'refund_requested', 'refunded', 'failed'])
            ->Notpos()
            ->latest()
            ->select('id', 'user_id', 'order_amount', 'order_status', 'otp')
            ->get();

        foreach ($orders as $value) {
            $value->order_status = ucfirst($value->order_status);
        }

        $data = [
            'orders' => $orders
        ];
        return response()->json($data, 200);
    }

    public function awt_place_order_latest(Request $request)
    {

        //  info("awtOrderAmount - ".$request->order_amount);
        //   info("awtPckageCharges -".$request->awt_package_charges);

        info("called awt_place_order_latest API");
        //info("Post  data ");
        info(json_encode($request->all()));
        $validator = Validator::make($request->all(), [
            'order_amount' => 'required',
            'payment_method' => 'required|in:cash_on_delivery,digital_payment,razor_pay,wallet',
            'order_type' => 'required|in:take_away,delivery',
            'restaurant_id' => 'required',
            'distance' => 'required_if:order_type,delivery',
            'address' => 'required_if:order_type,delivery',
            'longitude' => 'required_if:order_type,delivery',
            'latitude' => 'required_if:order_type,delivery',
            'dm_tips' => 'nullable|numeric'
        ]);

        // if ($validator->fails()) {
        //     info("order validation failed");
        //     info(json_encode(Helpers::error_processor($validator)));
        //     return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        // }

        if ($validator->fails()) {
            info("order validation failed");
            return array("status" => "invalid", "message" => $validator->errors()->first());
        }

        info("order validation success");

        if ($request->payment_method == 'wallet' && Helpers::get_business_settings('wallet_status', false) != 1) {
            return array("status" => "invalid", "message" => trans('messages.customer_wallet_disable_warning'));
        }
        $coupon = null;
        $delivery_charge = $request->delivery_charge ? $request->delivery_charge : null;
        $free_delivery_by = null;
        $awt_schedule_at = $request->schedule_at;
        $schedule_at = $request->schedule_at ? Carbon::parse($request->schedule_at) : now();
        if ($request->schedule_at && $schedule_at < now()) {
            return array("status" => "invalid", "message" => trans('messages.you_can_not_schedule_a_order_in_past'));
        }
        $restaurant = Restaurant::with('discount')->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = ' . $schedule_at->format('w') . ' and `restaurant_schedule`.`opening_time` < "' . $schedule_at->format('H:i:s') . '" and `restaurant_schedule`.`closing_time` >"' . $schedule_at->format('H:i:s') . '") > 0), true, false) as open')->where('id', $request->restaurant_id)->first();

        if (!$restaurant) {
            return array("status" => "invalid", "message" => trans('messages.restaurant_not_found'));
        }

        if ($request->schedule_at && !$restaurant->schedule_order) {
            return array("status" => "invalid", "message" => trans('messages.schedule_order_not_available'));
        }

        if ($restaurant->open == false) {
            return array("status" => "invalid", "message" => trans('messages.restaurant_is_closed_at_order_time'));
        }

        $user = User::find($request->user_id);
        if ($request['coupon_code']) {
            info("Coupon Code Exist");
            $custArr[] = (string) $request->restaurant_id;
            $strCustArr = json_encode($custArr);
            $coupon = Coupon::active()->where(['code' => $request['coupon_code'], 'data' => $strCustArr])->first();
            info('coupon data inside try is....' . $coupon);
            if (!$coupon) {
                info('if coupon not exists.... ');
                $zone_id = Restaurant::where('id', $request->restaurant_id)->value('zone_id');
                info('zone id is....' . $zone_id);

                $zoneArr = [$zone_id]; // Create an array with the zone_id

                $coupons = Coupon::active()
                    ->where('code', $request['coupon_code'])
                    ->get(); // Retrieve all matching coupons

                $matchingCoupons = $coupons->filter(function ($coupon) use ($zoneArr) {
                    $data = json_decode($coupon->data, true); // Decode the JSON data from the database

                    // Check if $zoneArr value exists in the decoded $data array
                    return in_array($zoneArr[0], $data);
                });

                $coupon = $matchingCoupons->first();
                info('if zone wise.......coupon data inside try is....' . $coupon);
            }
            if (isset($coupon)) {
                $staus = CouponLogic::is_valide($coupon, $user->id, $request['restaurant_id']);
                if ($staus == 407) {
                    return array("status" => "invalid", "message" => trans('messages.coupon_expire'));
                } else if ($staus == 406) {
                    return array("status" => "invalid", "message" => trans('messages.coupon_usage_limit_over'));
                } else if ($staus == 404) {
                    return array("status" => "invalid", "message" => trans('messages.not_found'));
                }
                if ($coupon->coupon_type == 'free_delivery') {
                    $delivery_charge = 0;
                    $coupon = null;
                    $free_delivery_by = 'admin';
                }
            } else {
                return array("status" => "invalid", "message" => trans('messages.not_found'));
            }
        }

        // if($request->latitude >= 13 && $request->latitude < 14){
        // $per_km_shipping_charge = 0;
        // $minimum_shipping_charge = 0;
        // }else if($request->latitude >= 15 && $request->latitude < 16){
        // $per_km_shipping_charge = 0;
        // $minimum_shipping_charge = 0;
        // }else{
        // $per_km_shipping_charge = (float)BusinessSetting::where(['key' => 'per_km_shipping_charge'])->first()->value;
        // $minimum_shipping_charge = (float)BusinessSetting::where(['key' => 'minimum_shipping_charge'])->first()->value;
        // }



        if ($request->latitude && $request->longitude) {
            info("Lat and Longs Success");
            $point = new Point($request->latitude, $request->longitude);
            $zone = Zone::where('id', $restaurant->zone_id)->contains('coordinates', $point)->first();
            if (!$zone) {
                // $errors = [];
                // array_push($errors, ['code' => 'coordinates', 'message' => trans('messages.out_of_coverage')]);
                // return response()->json([
                //     'errors' => $errors
                // ], 403);
                return array("status" => "invalid", "message" => trans('messages.out_of_coverage'));
            }
            if ($zone->per_km_shipping_charge >= 0 && $zone->minimum_shipping_charge >= 0) {
                $per_km_shipping_charge = $zone->per_km_shipping_charge;
                $minimum_shipping_charge = $zone->minimum_shipping_charge;
            }
        }

        if ($request['order_type'] != 'take_away' && !$restaurant->free_delivery && !isset($delivery_charge)) {
            info("request['order_type'] != 'take_away' && !restaurant->free_delivery && !isset(delivery_charge)");
            if ($restaurant->self_delivery_system) {
                $per_km_shipping_charge = $restaurant->per_km_shipping_charge;
                $minimum_shipping_charge = $restaurant->minimum_shipping_charge;
            }
        }

        if ($request->delivery_charge) {
            info("request->delivery_charge");
            $original_delivery_charge = $request->delivery_charge;
        } else {
            info("request->delivery_charge else condition");
            $distance_shipping_charge = $request->distance * $per_km_shipping_charge;
            $calculated_charge = max($distance_shipping_charge, $minimum_shipping_charge);
            $original_delivery_charge = $calculated_charge;
        }

        if ($request['order_type'] == 'take_away') {
            info("request['order_type'] == 'take_away'");
            $per_km_shipping_charge = 0;
            $minimum_shipping_charge = 0;
        }
        if (!isset($delivery_charge)) {
            info("!isset(delivery_charge)");
            $delivery_charge = ($request->distance * $per_km_shipping_charge > $minimum_shipping_charge) ? $request->distance * $per_km_shipping_charge : $minimum_shipping_charge;
        }


        $address = [
            'contact_person_name' => $request->contact_person_name ? $request->contact_person_name : $user->f_name . ' ' . $user->l_name,
            'contact_person_number' => $request->contact_person_number ? $request->contact_person_number : $user->phone,
            'address_type' => $request->address_type ? $request->address_type : 'Delivery',
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'longitude' => (string) $request->longitude,
            'latitude' => (string) $request->latitude,
        ];

        /*  $address = [
        'contact_person_name' => "Demo Customer",
        'contact_person_number' => "1111111111",
        'address_type' => "home",
        'address' => "test order address",
        'floor' => "test order floor",
        'road' => "test order road",
        'house' => "test order home",
        'longitude' => "82.85730799999999",
        'latitude' => " 17.54723469999999",
        ];*/



        $total_addon_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;

        $order_details = [];
        $order = new Order();
        $order->id = 100000 + Order::all()->count() + 1;
        if (Order::find($order->id)) {
            $order->id = Order::orderBy('id', 'desc')->first()->id + 1;
        }

        $order->user_id = $user->id;
        $order->order_amount = $request['order_amount'];

        $order->payment_status = $request['payment_method'] == 'wallet' ? 'paid' : 'unpaid';
        $order->order_status = $request['payment_method'] == 'digital_payment' ? 'failed' : ($request->payment_method == 'wallet' ? 'pending' : 'pending');
        $order->coupon_code = $request['coupon_code'];
        $order->payment_method = $request->payment_method;
        $order->transaction_reference = null;
        $order->order_note = $request['order_note'];
        $order->awt_delivery_notes = $request['cust_delivery_note'];
        $order->order_type = $request['order_type'];
        $order->restaurant_id = $request['restaurant_id'];
        $order->delivery_charge = round($delivery_charge, config('round_up_to_digit')) ?? 0;
        $order->awt_dm_base_fare = $request['awt_dm_base_fare'];
        $order->awt_dm_extra_fare = $request['awt_dm_extra_fare'];
        $order->original_delivery_charge = round($original_delivery_charge, config('round_up_to_digit'));
        $order->delivery_address = json_encode($address);
        $order->schedule_at = $schedule_at;
        $order->scheduled = $request->schedule_at ? 1 : 0;
        if ($request->awt_package_charges != NULL) {
            $order->awt_order_pckg_charge = $request->awt_package_charges;
        } else {
            $order->awt_order_pckg_charge = 0;
        }

        if ($order->schedule_at != NULL && $order->scheduled == 1) {
            $order->cust_order_status = "preorder";
            $order->cust_preorder = now();
            $order->awt_schedule_time = date("d-m-Y h:i", strtotime($schedule_at));
        } else {
            $order->cust_order_status = "pending";
            $order->awt_schedule_time = "n/a";
        }

        if ($request->payment_method == "razor_pay") {
            $order->order_status = "pending";
            $order->payment_status = 'paid';
            $order->transaction_reference = $request['razorpay_payment_id'];
        }

        $order->otp = rand(1000, 9999);
        $order->zone_id = $restaurant->zone_id;
        $dm_tips_manage_status = BusinessSetting::where('key', 'dm_tips_status')->first()->value;
        if ($dm_tips_manage_status == 1) {
            $order->dm_tips = $request->dm_tips ?? 0;
        } else {
            $order->dm_tips = 0;
        }
        $order->pending = now();
        // $order->confirmed = $request->payment_method == 'wallet' ? now() : null;
        $order->created_at = now();
        $order->updated_at = now();
        //   $request['cart'] = '[{"food_id":"5","item_campaign_id":null,"price":"141","variant":"","variation":[],"quantity":"1","add_on_ids":[],"add_ons":[],"add_on_qtys":[]}]';
        $awtCartDetails = $request['cart'];
        $req_cart = json_decode($request['cart'], true);
        //dd($req_cart);exit;
        foreach ($req_cart as $c) {
            info("req_cart foreach loop entered");
            if ($c['item_campaign_id'] != null) {
                $product = ItemCampaign::active()->find($c['item_campaign_id']);
                if ($product) {
                    if (count(json_decode($product['variations'], true)) > 0) {
                        $price = Helpers::variation_price($product, json_encode($c['variation']));
                    } else {
                        $price = $product['price'];
                    }
                    $product->tax = $restaurant->tax;
                    $product = Helpers::product_data_formatting($product, false, false, app()->getLocale());
                    $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id', $c['add_on_ids'])->get(), $c['add_on_qtys']);
                    $product["awt_food_qty"] = $c['quantity'];
                    // dd($product);exit;
                    //   $newArray=array("awt_food_qty"=>7);
                    //  $product1 = array_merge($product,$newArray);
                    $or_d = [
                        'food_id' => null,
                        'item_campaign_id' => $c['item_campaign_id'],
                        // 'food_details' => json_encode($product1),
                        'quantity' => $c['quantity'],
                        'price' => $price,
                        'tax_amount' => Helpers::tax_calculate($product, $price),
                        'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
                        'discount_type' => 'discount_on_product',
                        'variant' => json_encode($c['variant']),
                        'variation' => json_encode($c['variation']),
                        'add_ons' => json_encode($addon_data['addons']),
                        'total_add_on_price' => $addon_data['total_add_on_price'],
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $order_details[] = $or_d;
                    $total_addon_price += $or_d['total_add_on_price'];
                    $product_price += $price * $or_d['quantity'];
                    $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'];
                } else {
                    return array("status" => "invalid", "message" => trans('messages.product_unavailable_warning'));
                    // return response()->json([
                    //     'errors' => [
                    //         ['code' => 'campaign', 'message' => trans('messages.product_unavailable_warning')]
                    //     ]
                    // ], 401);
                }
            } else {
                $product = Food::active()->find($c['food_id']);
                if ($product) {
                    if (count(json_decode($product['variations'], true)) > 0) {
                        if (count($c['variation']) > 0) {
                            $price = Helpers::variation_price($product, json_encode($c['variation']));
                        } else {
                            $price = $product['price'];
                        }
                    } else {
                        $price = $product['price'];
                    }
                    $product->tax = $restaurant->tax;
                    $product = Helpers::product_data_formatting($product, false, false, app()->getLocale());
                    $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id', $c['add_on_ids'])->get(), $c['add_on_qtys']);
                    $product["awt_food_qty"] = $c['quantity'];
                    $or_d = [
                        'food_id' => $c['food_id'],
                        'item_campaign_id' => null,
                        'food_details' => json_encode($product),
                        'quantity' => $c['quantity'],
                        'price' => round($price, config('round_up_to_digit')),
                        'tax_amount' => round(Helpers::tax_calculate($product, $price), config('round_up_to_digit')),
                        'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
                        'discount_type' => 'discount_on_product',
                        'variant' => json_encode($c['variant']),
                        'variation' => json_encode($c['variation']),
                        'add_ons' => json_encode($addon_data['addons']),
                        'total_add_on_price' => round($addon_data['total_add_on_price'], config('round_up_to_digit')),
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $total_addon_price += $or_d['total_add_on_price'];
                    $product_price += $price * $or_d['quantity'];
                    $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'];
                    $order_details[] = $or_d;
                } else {
                    return array("status" => "invalid", "message" => trans('messages.product_unavailable_warning'));
                    // return response()->json([
                    //     'errors' => [
                    //         ['code' => 'food', 'message' => trans('messages.product_unavailable_warning')]
                    //     ]
                    // ], 401);
                }
            }

        }
        $restaurant_discount = Helpers::get_restaurant_discount($restaurant);
        if (isset($restaurant_discount)) {
            info("isset(restaurant_discount)");
            if ($product_price + $total_addon_price < $restaurant_discount['min_purchase']) {
                $restaurant_discount_amount = 0;
            }

            if ($restaurant_discount_amount > $restaurant_discount['max_discount']) {
                $restaurant_discount_amount = $restaurant_discount['max_discount'];
            }
        }
        $coupon_discount_amount = $coupon ? CouponLogic::get_discount($coupon, $product_price + $total_addon_price - $restaurant_discount_amount) : 0;
        $total_price = $product_price + $total_addon_price - $restaurant_discount_amount - $coupon_discount_amount;

        $tax = $restaurant->tax;
        $total_tax_amount = ($tax > 0) ? (($total_price * $tax) / 100) : 0;

        if ($restaurant->minimum_order > $product_price + $total_addon_price) {
            return array("status" => "invalid", "message" => trans('messages.you_need_to_order_at_least', ['amount' => $restaurant->minimum_order . ' ' . Helpers::currency_code()]));
            // return response()->json([
            //     'errors' => [
            //         ['code' => 'order_time', 'message' => trans('messages.you_need_to_order_at_least', ['amount' => $restaurant->minimum_order . ' ' . Helpers::currency_code()])]
            //     ]
            // ], 406);
        }

        $free_delivery_over = BusinessSetting::where('key', 'free_delivery_over')->first()->value;
        if (isset($free_delivery_over)) {
            info("isset(free_delivery_over)");
            if ($free_delivery_over <= $product_price + $total_addon_price - $coupon_discount_amount - $restaurant_discount_amount) {
                $order->delivery_charge = 0;
                $free_delivery_by = 'admin';
            }
        }

        if ($restaurant->free_delivery) {
            $order->delivery_charge = 0;
            $free_delivery_by = 'vendor';
        }

        if ($coupon) {
            $coupon->increment('total_uses');
        }

        if ($request->awt_package_charges != NULL) {
            $awt_package_charges = $request->awt_package_charges;
        } else {
            $awt_package_charges = 0;
        }

        $order_amount = round($total_price + $total_tax_amount + $awt_package_charges + $order->delivery_charge, config('round_up_to_digit'));

        //   $order_amount = round($total_price + $total_tax_amount + $order->delivery_charge , config('round_up_to_digit'));

        if ($request->payment_method == 'wallet' && $user->wallet_balance < $order_amount) {
            return array("status" => "invalid", "message" => trans('messages.insufficient_balance'));
            // return response()->json([
            //     'errors' => [
            //         ['code' => 'order_amount', 'message' => trans('messages.insufficient_balance')]
            //     ]
            // ], 203);
        }
        // dd($order->dm_tips);
        try {
            info("Inside the try");
            $order->coupon_discount_amount = round($coupon_discount_amount, config('round_up_to_digit'));
            $order->coupon_discount_title = $coupon ? $coupon->title : '';
            $order->free_delivery_by = $free_delivery_by;
            $order->restaurant_discount_amount = round($restaurant_discount_amount, config('round_up_to_digit'));
            $order->total_tax_amount = round($total_tax_amount, config('round_up_to_digit'));
            // $order->order_amount = $order_amount + $order->dm_tips;
            $order->order_amount = $order_amount;
            $order->awtOrderCartDetails = $awtCartDetails;
            if ($request->wallet_amount != '') {
                $order->wallet_amount = $request->wallet_amount;
            }
            if ($request->payment_method == 'wallet') {
                // $order->wallet_amount = $order_amount + $order->dm_tips;
                $order->wallet_amount = $order_amount;
            }
            $order->save();
            if ($request->wallet_amount != '') {
                CustomerLogic::create_wallet_transaction($order->user_id, $request->wallet_amount, 'order_place', $order->id);
            }
            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;
            }
            OrderDetail::insert($order_details);
            Helpers::send_order_notification($order);

            $customer = $user;
            $customer->zone_id = $restaurant->zone_id;
            $customer->save();
            // dd($customer);
            //exit;

            $restaurant->increment('total_order');
            if ($request->payment_method == 'wallet')
                CustomerLogic::create_wallet_transaction($order->user_id, $order->order_amount, 'order_place', $order->id);

            try {
                if ($order->order_status == 'pending') {
                    Mail::to($customer['email'])->send(new \App\Mail\OrderPlaced($order->id));
                }
            } catch (\Exception $ex) {
                info($ex);
            }

            try {
                if ($order->order_status == 'pending' || $order->order_status == 'confirmed') {
                    $mobile = $customer['phone'];
                    $message = "Dear " . $customer['f_name'] . "

Order has been placed @ " . date('d M Y, h:i A', strtotime($order->schedule_at)) . "- for store " . $restaurant['name'] . " Order ID: " . $order->id . "

Thanks and Regards
Food Ride";
                    $template_id = 1407169268569030169;
                    Helpers::send_cmoon_sms($mobile, $message, $template_id);
                }
            } catch (\Exception $ex) {
                info($ex);
            }
            info("New Order Created Log --> end");
            info($order->id);
            $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order->id, $order->user_id, 'pending');
            return response()->json([
                "status" => "valid",
                'message' => trans('messages.order_placed_successfully'),
                'order_id' => $order->id,
                'total_ammount' => $total_price + $order->delivery_charge + $total_tax_amount
            ], 200);
        } catch (\Exception $e) {
            info("error awt_place_order method");
            info($e);
            return response()->json([$e], 403);
        }

        // return array("status" => "invalid", "message" => trans('messages.failed_to_place_order'));
        // return response()->json([
        //     'errors' => [
        //         ['code' => 'order_time', 'message' => trans('messages.failed_to_place_order')]
        //     ]
        // ], 403);
    }
}
