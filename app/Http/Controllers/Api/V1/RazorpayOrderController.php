<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\CouponLogic;
use App\CentralLogics\CustomerLogic;
use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Coupon;
use App\Models\Food;
use App\Models\ItemCampaign;
use App\Models\OrderDetail;
use App\Models\Restaurant;
use App\Models\Zone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use App\Models\Order;
use Illuminate\Support\Facades\Mail;
use Razorpay\Api\Api;
use App\Models\User;

class RazorpayOrderController extends Controller
{
    public function razorpay_place_order(Request $request)
    {
        // info(json_encode($request->all()));
        $validator = Validator::make($request->all(), [
            'order_amount' => 'required',
            'payment_method' => 'required|in:razor_pay',
            'order_type' => 'required|in:take_away,delivery',
            'restaurant_id' => 'required',
            'distance' => 'required_if:order_type,delivery',
            'address' => 'required_if:order_type,delivery',
            'longitude' => 'required_if:order_type,delivery',
            'latitude' => 'required_if:order_type,delivery',
            'dm_tips' => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            info("Razorpay order validation failed");
            return array("status" => "invalid", "message" => $validator->errors()->first());
        }

        info("Razorpay order validation success");

        $api = new Api(config('razor.razor_key'), config('razor.razor_secret'));

        $order = $api->order->create(array('receipt' => '123', 'amount' => $request->order_amount * 100, 'currency' => 'INR')); // Creates order
        $orderId = $order['id'];

        $coupon = null;
        $delivery_charge = $request->delivery_charge ? $request->delivery_charge : null;
        $free_delivery_by = null;
        $awt_schedule_at = $request->schedule_at;
        $schedule_at = $request->schedule_at ? Carbon::parse($request->schedule_at) : now();
        if ($request->schedule_at && $schedule_at < now()) {
            return array("status" => "invalid", "message" => trans('messages.you_can_not_schedule_a_order_in_past'));
        }
        $restaurant = Restaurant::with('discount')
            ->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = ' . $schedule_at->format('w') . ' and `restaurant_schedule`.`opening_time` < "' . $schedule_at->format('H:i:s') . '" and `restaurant_schedule`.`closing_time` >"' . $schedule_at->format('H:i:s') . '") > 0), true, false) as open')
            ->where('id', $request->restaurant_id)
            ->first();

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

        if ($request->latitude && $request->longitude) {
            info("Lat and Longs Success");
            $point = new Point($request->latitude, $request->longitude);
            $zone = Zone::where('id', $restaurant->zone_id)->contains('coordinates', $point)->first();
            if (!$zone) {
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

        $order->payment_status = 'unpaid';
        $order->order_status = 'failed';
        $order->failed = now();
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
        $order->transaction_reference = $orderId;

        // if ($request->payment_method == "razor_pay") {
        //     $order->order_status = "pending";
        //     $order->payment_status = 'paid';
        //     $order->transaction_reference = $request['razorpay_payment_id'];
        // }

        $order->otp = rand(1000, 9999);
        $order->zone_id = $restaurant->zone_id;
        $dm_tips_manage_status = BusinessSetting::where('key', 'dm_tips_status')->first()->value;
        if ($dm_tips_manage_status == 1) {
            $order->dm_tips = $request->dm_tips ?? 0;
        } else {
            $order->dm_tips = 0;
        }
        // $order->pending = now();
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
                        // 'tax_amount' => round(Helpers::tax_calculate($product, round($price / $c['quantity'], config('round_up_to_digit'))), config('round_up_to_digit')),
                        'tax_amount' => $request->tax_amount,
                        // 'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
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

        if ($order->schedule_at != NULL && $order->scheduled == 1) {
            $order->cust_order_status = "preorder";
            $order->cust_preorder = now();
            $order->awt_schedule_time = date("d-m-Y h:i", strtotime($schedule_at));
        } else {
            $order->cust_order_status = "pending";
            $order->awt_schedule_time = "n/a";
        }

        // $order_amount = round($total_price + $total_tax_amount + $awt_package_charges + $order->delivery_charge, config('round_up_to_digit'));

        $order_amount = $request->order_amount;

        //   $order_amount = round($total_price + $total_tax_amount + $order->delivery_charge , config('round_up_to_digit'));
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
            // if ($request->payment_method == 'wallet') {
            //     $order->wallet_amount = $order_amount + $order->dm_tips;
            //     $order->order_amount = $order_amount;
            // }
            $order->save();
            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;
            }
            OrderDetail::insert($order_details);
            // Helpers::send_order_notification($order);

            $customer = $request->user();
            $customer->zone_id = $restaurant->zone_id;
            $customer->save();
            // dd($customer);
            //exit;

            $restaurant->increment('total_order');

            try {
                if ($order->order_status == 'pending') {
                    Mail::to($customer['email'])->send(new \App\Mail\OrderPlaced($order->id));
                }
            } catch (\Exception $ex) {
                info($ex);
            }

            $data = [
                'key' => config('razor.razor_key'),
                'order_id' => $order->id,
                'amount' => ($order->order_amount - $order->wallet_amount) * 100,
                'name' => 'Food Ride',
                'currency' => 'INR',
                'razorpay_order_id' => $orderId
            ];
            info("New Order Created Log --> end......");
            // $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order->id, $order->user_id, 'pending');
            return response()->json([
                "status" => "valid",
                'message' => trans('messages.order_initiated_successfully'),
                'data' => $data
            ], 200);
        } catch (\Exception $e) {
            info("error awt_place_order method");
            info($e);
            return response()->json([$e], 403);
        }
    }
    public function razorpay_place_order_with_petpooja(Request $request)
    {
        info(json_encode($request->all()));
        $validator = Validator::make($request->all(), [
            'order_amount' => 'required',
            'payment_method' => 'required|in:razor_pay',
            'order_type' => 'required|in:take_away,delivery',
            'restaurant_id' => 'required',
            'distance' => 'required_if:order_type,delivery',
            'address' => 'required_if:order_type,delivery',
            'longitude' => 'required_if:order_type,delivery',
            'latitude' => 'required_if:order_type,delivery',
            'dm_tips' => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            info("Razorpay order validation failed");
            return array("status" => "invalid", "message" => $validator->errors()->first());
        }

        info("Razorpay order validation success");

        $api = new Api(config('razor.razor_key'), config('razor.razor_secret'));

        $order = $api->order->create(array('receipt' => '123', 'amount' => ($request->order_amount - ($request->wallet_amount != 0 ? $request->wallet_amount : 0)) * 100, 'currency' => 'INR')); // Creates order
        $orderId = $order['id'];

        $coupon = null;
        $delivery_charge = $request->delivery_charge ? $request->delivery_charge : null;
        $free_delivery_by = null;
        $awt_schedule_at = $request->schedule_at;
        $schedule_at = $request->schedule_at ? Carbon::parse($request->schedule_at) : now();
        if ($request->schedule_at && $schedule_at < now()) {
            return array("status" => "invalid", "message" => trans('messages.you_can_not_schedule_a_order_in_past'));
        }
        $restaurant = Restaurant::with('discount')
            ->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = ' . $schedule_at->format('w') . ' and `restaurant_schedule`.`opening_time` < "' . $schedule_at->format('H:i:s') . '" and `restaurant_schedule`.`closing_time` >"' . $schedule_at->format('H:i:s') . '") > 0), true, false) as open')
            ->where('id', $request->restaurant_id)
            ->first();

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

        if ($request->latitude && $request->longitude) {
            info("Lat and Longs Success");
            $point = new Point($request->latitude, $request->longitude);
            $zone = Zone::where('id', $restaurant->zone_id)->contains('coordinates', $point)->first();
            if (!$zone) {
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

        $order->payment_status = 'unpaid';
        $order->order_status = 'failed';
        $order->failed = now();
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
        $order->transaction_reference = $orderId;

        // if ($request->payment_method == "razor_pay") {
        //     $order->order_status = "pending";
        //     $order->payment_status = 'paid';
        //     $order->transaction_reference = $request['razorpay_payment_id'];
        // }

        $order->otp = rand(1000, 9999);
        $order->zone_id = $restaurant->zone_id;
        $dm_tips_manage_status = BusinessSetting::where('key', 'dm_tips_status')->first()->value;
        if ($dm_tips_manage_status == 1) {
            $order->dm_tips = $request->dm_tips ?? 0;
        } else {
            $order->dm_tips = 0;
        }
        // $order->pending = now();
        // $order->confirmed = $request->payment_method == 'wallet' ? now() : null;
        $order->created_at = now();
        $order->updated_at = now();
        //   $request['cart'] = '[{"food_id":"5","item_campaign_id":null,"price":"141","variant":"","variation":[],"quantity":"1","add_on_ids":[],"add_ons":[],"add_on_qtys":[]}]';
        $awtCartDetails = $request['cart'];
        $req_cart = json_decode($request['cart'], true);
        //dd($req_cart);exit;
        foreach ($req_cart as $c) {
            info("req_cart foreach loop entered");
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
                    // if (count($c['variation']) > 0) {
                    //     $price = Helpers::variation_price($product, json_encode($c['variation']));
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
                    // 'tax_amount' => round(Helpers::tax_calculate($product, round($price / $c['quantity'], config('round_up_to_digit'))), config('round_up_to_digit')),
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
            }
            // }
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
        $coupon_discount_amount = $coupon ? CouponLogic::get_discount($coupon, $product_price - $restaurant_discount_amount) : 0;
        $total_price = $product_price - $restaurant_discount_amount - $coupon_discount_amount;

        $tax = $restaurant->tax;
        $total_tax_amount = $modified_tax > 0 ? $modified_tax : (($tax > 0) ? (($total_price * $tax) / 100) : 0);

        if ($restaurant->minimum_order > $product_price + $total_addon_price) {
            return array("status" => "invalid", "message" => trans('messages.you_need_to_order_at_least', ['amount' => $restaurant->minimum_order . ' ' . Helpers::currency_code()]));
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

        if ($order->schedule_at != NULL && $order->scheduled == 1) {
            $order->cust_order_status = "preorder";
            $order->cust_preorder = now();
            $order->awt_schedule_time = date("d-m-Y h:i", strtotime($schedule_at));
        } else {
            $order->cust_order_status = "pending";
            $order->awt_schedule_time = "n/a";
        }

        // $order_amount = round($total_price + $total_tax_amount + $awt_package_charges + $order->delivery_charge, config('round_up_to_digit'));

        $order_amount = $request->order_amount;

        //   $order_amount = round($total_price + $total_tax_amount + $order->delivery_charge , config('round_up_to_digit'));
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
            // if ($request->payment_method == 'wallet') {
            //     $order->wallet_amount = $order_amount + $order->dm_tips;
            //     $order->wallet_amount = $order_amount;
            // }
            $order->save();
            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;
            }
            OrderDetail::insert($order_details);
            // Helpers::send_order_notification($order);
            // Helpers::petPoojaSaveOrder($order->id);

            $customer = $request->user();
            $customer->zone_id = $restaurant->zone_id;
            $customer->save();
            // dd($customer);
            //exit;

            $restaurant->increment('total_order');

            try {
                if ($order->order_status == 'pending') {
                    Mail::to($customer['email'])->send(new \App\Mail\OrderPlaced($order->id));
                }
            } catch (\Exception $ex) {
                info($ex);
            }

            $data = [
                'key' => config('razor.razor_key'),
                'order_id' => $order->id,
                'amount' => ($order->order_amount - $order->wallet_amount) * 100,
                'name' => 'Food Ride',
                'currency' => 'INR',
                'razorpay_order_id' => $orderId
            ];
            info("New Order Created Log --> end......");
            // $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order->id, $order->user_id, 'pending');
            return response()->json([
                "status" => "valid",
                'message' => trans('messages.order_initiated_successfully'),
                'data' => $data
            ], 200);
        } catch (\Exception $e) {
            info("error awt_place_order method");
            info($e);
            return response()->json([$e], 403);
        }
    }

    public function razorpay_success(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'razorpay_order_id' => 'required',
            'razorpay_payment_id' => 'required',
            // 'razorpay_signature' => 'required',
        ]);
        if ($validator->fails()) {
            return array("status" => "invalid", "message" => $validator->errors()->first());
        }

        $order = Order::where('transaction_reference', $request->razorpay_order_id)->first();
        $order->order_status = "pending";
        $order->payment_status = 'paid';
        $order->transaction_reference = $request['razorpay_payment_id'];
        $order->pending = now();
        $order->save();

        if ($order->wallet_amount != 0 && $order->wallet_amount != '') {
            CustomerLogic::create_wallet_transaction($order->user_id, $order->wallet_amount, 'order_place', $order->id);
        }

        try {
            $customer = $request->user();
            $restaurant = Restaurant::where('id', $order->restaurant_id)->first();
            if ($order->order_status == 'pending' || $order->order_status == 'confirmed') {
                $mobile = $customer['phone'];
                $message = "Dear " . $customer['f_name'] . "

Order has been placed @ " . date('d M Y, h:i A', strtotime($order->schedule_at)) . "- for store " . $restaurant['name'] . " Order ID: " . $order->id . "

Thanks and Regards
Food Ride";
                $template_id = 1407169268569030169;
                Helpers::send_cmoon_sms($mobile, $message, $template_id);
                Helpers::petPoojaSaveOrder($order->id);
            }
        } catch (\Exception $ex) {
            info($ex);
        }

        if ($order->order_status == 'pending') {
            try {
                Mail::to($customer['email'])->send(new \App\Mail\OrderPlaced($order->id));
            } catch (\Exception $ex) {
                info($ex);
            }
        }

        Helpers::send_order_notification($order);
        Helpers::awt_order_push_cust_notif_to_topic($order->id, $order->user_id, 'pending');

        return response()->json([
            "status" => "valid",
            'message' => trans('messages.order_placed_successfully')
        ]);
    }

    public function razorpay_failure(Request $request)
    {
        // Failure Conditions
    }

    public function razorpay_place_order_latest(Request $request)
    {
        info(json_encode($request->all()));
        $validator = Validator::make($request->all(), [
            'order_amount' => 'required',
            'payment_method' => 'required|in:razor_pay',
            'order_type' => 'required|in:take_away,delivery',
            'restaurant_id' => 'required',
            'distance' => 'required_if:order_type,delivery',
            'address' => 'required_if:order_type,delivery',
            'longitude' => 'required_if:order_type,delivery',
            'latitude' => 'required_if:order_type,delivery',
            'dm_tips' => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            info("Razorpay Latest order validation failed");
            return array("status" => "invalid", "message" => $validator->errors()->first());
        }

        info("Razorpay Latest order validation success");

        $user = User::find($request->user_id);

        $api = new Api(config('razor.razor_key'), config('razor.razor_secret'));

        $order = $api->order->create(array('receipt' => '123', 'amount' => $request->order_amount * 100, 'currency' => 'INR')); // Creates order
        $orderId = $order['id'];

        $coupon = null;
        $delivery_charge = $request->delivery_charge ? $request->delivery_charge : null;
        $free_delivery_by = null;
        $awt_schedule_at = $request->schedule_at;
        $schedule_at = $request->schedule_at ? Carbon::parse($request->schedule_at) : now();
        if ($request->schedule_at && $schedule_at < now()) {
            return array("status" => "invalid", "message" => trans('messages.you_can_not_schedule_a_order_in_past'));
        }
        $restaurant = Restaurant::with('discount')
            ->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = ' . $schedule_at->format('w') . ' and `restaurant_schedule`.`opening_time` < "' . $schedule_at->format('H:i:s') . '" and `restaurant_schedule`.`closing_time` >"' . $schedule_at->format('H:i:s') . '") > 0), true, false) as open')
            ->where('id', $request->restaurant_id)
            ->first();

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

        if ($request->latitude && $request->longitude) {
            info("Lat and Longs Success");
            $point = new Point($request->latitude, $request->longitude);
            $zone = Zone::where('id', $restaurant->zone_id)->contains('coordinates', $point)->first();
            if (!$zone) {
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

        $order->payment_status = 'unpaid';
        $order->order_status = 'failed';
        $order->failed = now();
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
        $order->transaction_reference = $orderId;

        // if ($request->payment_method == "razor_pay") {
        //     $order->order_status = "pending";
        //     $order->payment_status = 'paid';
        //     $order->transaction_reference = $request['razorpay_payment_id'];
        // }

        $order->otp = rand(1000, 9999);
        $order->zone_id = $restaurant->zone_id;
        $dm_tips_manage_status = BusinessSetting::where('key', 'dm_tips_status')->first()->value;
        if ($dm_tips_manage_status == 1) {
            $order->dm_tips = $request->dm_tips ?? 0;
        } else {
            $order->dm_tips = 0;
        }
        // $order->pending = now();
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
                        // 'tax_amount' => round(Helpers::tax_calculate($product, round($price / $c['quantity'], config('round_up_to_digit'))), config('round_up_to_digit')),
                        'tax_amount' => $request->tax_amount,
                        // 'discount_on_food' => Helpers::product_discount_calculate($product, $price, $restaurant),
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

        if ($order->schedule_at != NULL && $order->scheduled == 1) {
            $order->cust_order_status = "preorder";
            $order->cust_preorder = now();
            $order->awt_schedule_time = date("d-m-Y h:i", strtotime($schedule_at));
        } else {
            $order->cust_order_status = "pending";
            $order->awt_schedule_time = "n/a";
        }

        // $order_amount = round($total_price + $total_tax_amount + $awt_package_charges + $order->delivery_charge, config('round_up_to_digit'));

        $order_amount = $request->order_amount;

        //   $order_amount = round($total_price + $total_tax_amount + $order->delivery_charge , config('round_up_to_digit'));
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
            // if ($request->payment_method == 'wallet') {
            //     $order->wallet_amount = $order_amount + $order->dm_tips;
            //     $order->wallet_amount = $order_amount;
            // }
            $order->save();
            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;
            }
            OrderDetail::insert($order_details);
            // Helpers::send_order_notification($order);

            $customer = $user;
            $customer->zone_id = $restaurant->zone_id;
            $customer->save();
            // dd($customer);
            //exit;

            $restaurant->increment('total_order');


            // if ($order->order_status == 'pending') {
            //     try {
            //         Mail::to($customer['email'])->send(new \App\Mail\OrderPlaced($order->id));
            //     } catch (\Exception $ex) {
            //         info($ex);
            //     }
            // }

            $data = [
                'key' => config('razor.razor_key'),
                'order_id' => $order->id,
                'amount' => ($order->order_amount - $order->wallet_amount) * 100,
                'name' => 'Food Ride',
                'currency' => 'INR',
                'razorpay_order_id' => $orderId
            ];
            info("New Order Created Log --> end");
            // $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order->id, $order->user_id, 'pending');
            return response()->json([
                "status" => "valid",
                'message' => trans('messages.order_initiated_successfully'),
                'data' => $data
            ], 200);
        } catch (\Exception $e) {
            info("error awt_place_order method");
            info($e);
            return response()->json([$e], 403);
        }
    }
    public function razorpay_place_order_latest_petpooja(Request $request)
    {
        info(json_encode($request->all()));
        $validator = Validator::make($request->all(), [
            'order_amount' => 'required',
            'payment_method' => 'required|in:razor_pay',
            'order_type' => 'required|in:take_away,delivery',
            'restaurant_id' => 'required',
            'distance' => 'required_if:order_type,delivery',
            'address' => 'required_if:order_type,delivery',
            'longitude' => 'required_if:order_type,delivery',
            'latitude' => 'required_if:order_type,delivery',
            'dm_tips' => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            info("Razorpay Latest order validation failed");
            return array("status" => "invalid", "message" => $validator->errors()->first());
        }

        info("Razorpay Latest order validation success");

        $user = User::find($request->user_id);

        $api = new Api(config('razor.razor_key'), config('razor.razor_secret'));

        $order = $api->order->create(array('receipt' => '123', 'amount' => ($request->order_amount - ($request->wallet_amount != 0 ? $request->wallet_amount : 0)) * 100, 'currency' => 'INR')); // Creates order
        $orderId = $order['id'];

        $coupon = null;
        $delivery_charge = $request->delivery_charge ? $request->delivery_charge : null;
        $free_delivery_by = null;
        $awt_schedule_at = $request->schedule_at;
        $schedule_at = $request->schedule_at ? Carbon::parse($request->schedule_at) : now();
        if ($request->schedule_at && $schedule_at < now()) {
            return array("status" => "invalid", "message" => trans('messages.you_can_not_schedule_a_order_in_past'));
        }
        $restaurant = Restaurant::with('discount')
            ->selectRaw('*, IF(((select count(*) from `restaurant_schedule` where `restaurants`.`id` = `restaurant_schedule`.`restaurant_id` and `restaurant_schedule`.`day` = ' . $schedule_at->format('w') . ' and `restaurant_schedule`.`opening_time` < "' . $schedule_at->format('H:i:s') . '" and `restaurant_schedule`.`closing_time` >"' . $schedule_at->format('H:i:s') . '") > 0), true, false) as open')
            ->where('id', $request->restaurant_id)
            ->first();

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

        if ($request->latitude && $request->longitude) {
            info("Lat and Longs Success");
            $point = new Point($request->latitude, $request->longitude);
            $zone = Zone::where('id', $restaurant->zone_id)->contains('coordinates', $point)->first();
            if (!$zone) {
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

        $order->user_id = $user->id;
        $order->order_amount = $request['order_amount'];

        $order->payment_status = 'unpaid';
        $order->order_status = 'failed';
        $order->failed = now();
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
        $order->transaction_reference = $orderId;

        // if ($request->payment_method == "razor_pay") {
        //     $order->order_status = "pending";
        //     $order->payment_status = 'paid';
        //     $order->transaction_reference = $request['razorpay_payment_id'];
        // }

        $order->otp = rand(1000, 9999);
        $order->zone_id = $restaurant->zone_id;
        $dm_tips_manage_status = BusinessSetting::where('key', 'dm_tips_status')->first()->value;
        if ($dm_tips_manage_status == 1) {
            $order->dm_tips = $request->dm_tips ?? 0;
        } else {
            $order->dm_tips = 0;
        }
        // $order->pending = now();
        // $order->confirmed = $request->payment_method == 'wallet' ? now() : null;
        $order->created_at = now();
        $order->updated_at = now();
        //   $request['cart'] = '[{"food_id":"5","item_campaign_id":null,"price":"141","variant":"","variation":[],"quantity":"1","add_on_ids":[],"add_ons":[],"add_on_qtys":[]}]';
        $awtCartDetails = $request['cart'];
        $req_cart = json_decode($request['cart'], true);
        //dd($req_cart);exit;
        foreach ($req_cart as $c) {
            info("req_cart foreach loop entered");
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
                    // 'tax_amount' => round(Helpers::tax_calculate($product, round($price / $c['quantity'], config('round_up_to_digit'))), config('round_up_to_digit')),
                    'tax_amount' => $request->tax_amount,
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
            }
            // }
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
        $coupon_discount_amount = $coupon ? CouponLogic::get_discount($coupon, $product_price - $restaurant_discount_amount) : 0;
        $total_price = $product_price - $restaurant_discount_amount - $coupon_discount_amount;

        $tax = $restaurant->tax;
        $total_tax_amount = $modified_tax > 0 ? $modified_tax : (($tax > 0) ? (($total_price * $tax) / 100) : 0);

        if ($restaurant->minimum_order > $product_price + $total_addon_price) {
            return array("status" => "invalid", "message" => trans('messages.you_need_to_order_at_least', ['amount' => $restaurant->minimum_order . ' ' . Helpers::currency_code()]));
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

        if ($order->schedule_at != NULL && $order->scheduled == 1) {
            $order->cust_order_status = "preorder";
            $order->cust_preorder = now();
            $order->awt_schedule_time = date("d-m-Y h:i", strtotime($schedule_at));
        } else {
            $order->cust_order_status = "pending";
            $order->awt_schedule_time = "n/a";
        }

        // $order_amount = round($total_price + $total_tax_amount + $awt_package_charges + $order->delivery_charge, config('round_up_to_digit'));

        $order_amount = $request->order_amount;

        //   $order_amount = round($total_price + $total_tax_amount + $order->delivery_charge , config('round_up_to_digit'));
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
            // if ($request->payment_method == 'wallet') {
            //     $order->wallet_amount = $order_amount + $order->dm_tips;
            //     $order->wallet_amount = $order_amount;
            // }
            $order->save();
            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;
            }
            OrderDetail::insert($order_details);
            // Helpers::send_order_notification($order);

            $customer = $user;
            $customer->zone_id = $restaurant->zone_id;
            $customer->save();
            // dd($customer);
            //exit;

            $restaurant->increment('total_order');


            // if ($order->order_status == 'pending') {
            //     try {
            //         Mail::to($customer['email'])->send(new \App\Mail\OrderPlaced($order->id));
            //     } catch (\Exception $ex) {
            //         info($ex);
            //     }
            // }

            $data = [
                'key' => config('razor.razor_key'),
                'order_id' => $order->id,
                'amount' => ($order->order_amount - $order->wallet_amount) * 100,
                'name' => 'Food Ride',
                'currency' => 'INR',
                'razorpay_order_id' => $orderId
            ];
            info("New Order Created Log --> end");
            // $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order->id, $order->user_id, 'pending');
            return response()->json([
                "status" => "valid",
                'message' => trans('messages.order_initiated_successfully'),
                'data' => $data
            ], 200);
        } catch (\Exception $e) {
            info("error awt_place_order method");
            info($e);
            return response()->json([$e], 403);
        }
    }

    public function razorpay_success_latest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'razorpay_order_id' => 'required',
            'razorpay_payment_id' => 'required',
            // 'razorpay_signature' => 'required',
        ]);
        if ($validator->fails()) {
            return array("status" => "invalid", "message" => $validator->errors()->first());
        }

        $order = Order::where('transaction_reference', $request->razorpay_order_id)->first();
        $order->order_status = "pending";
        $order->payment_status = 'paid';
        $order->transaction_reference = $request['razorpay_payment_id'];
        $order->pending = now();
        $order->save();

        if ($order->wallet_amount != 0 && $order->wallet_amount != '') {
            CustomerLogic::create_wallet_transaction($order->user_id, $order->wallet_amount, 'order_place', $order->id);
        }

        try {
            $customer = User::find($order->user_id);
            $restaurant = Restaurant::where('id', $order->restaurant_id)->first();
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

        if ($order->order_status == 'pending') {
            try {
                Mail::to($customer['email'])->send(new \App\Mail\OrderPlaced($order->id));
            } catch (\Exception $ex) {
                info($ex);
            }
        }

        Helpers::send_order_notification($order);
        Helpers::awt_order_push_cust_notif_to_topic($order->id, $order->user_id, 'pending');
        Helpers::petPoojaSaveOrder($order->id);

        return response()->json([
            "status" => "valid",
            'message' => trans('messages.order_placed_successfully')
        ]);
    }
}
