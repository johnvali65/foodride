<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ServiceOrderPidgeLog;

ini_set('memory_limit', '-1');

use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use App\Http\Controllers\Controller;
use App\Models\DeliveryHistory;
use App\Models\DeliveryMan;
use App\Models\Restaurant;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\User;
use App\Models\OrderTransaction;
use App\Models\DeliveryManWallet;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\BusinessSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use App\CentralLogics\CustomerLogic;

// Carbon::setWeekStartsAt(Carbon::SUNDAY);
// Carbon::setWeekEndsAt(Carbon::SATURDAY);


class DeliverymanController extends Controller
{

    public function get_profile(Request $request)
    {
        $dm = DeliveryMan::with(['rating', 'userinfo'])->where(['auth_token' => $request['token']])->first();
        $dm['avg_rating'] = (double) (!empty($dm->rating[0]) ? $dm->rating[0]->average : 0);
        $dm['rating_count'] = (double) (!empty($dm->rating[0]) ? $dm->rating[0]->rating_count : 0);
        $dm['order_count'] = (integer) $dm->orders->count();
        $dm['todays_order_count'] = (integer) $dm->todaysorders->count();
        $dm['this_week_order_count'] = (integer) $dm->this_week_orders->count();
        $dm['member_since_days'] = (integer) $dm->created_at->diffInDays();
        $dm['cash_in_hands'] = $dm->wallet ? $dm->wallet->collected_cash : 0;
        $dm['balance'] = $dm->wallet ? $dm->wallet->total_earning - $dm->wallet->total_withdrawn : 0;
        $dm['todays_earning'] = (float) ($dm->todays_earning()->sum('delivery_charge') + $dm->todays_earning()->sum('dm_tips'));
        $dm['this_week_earning'] = (float) ($dm->this_week_earning()->sum('delivery_charge') + $dm->this_week_earning()->sum('dm_tips'));
        $dm['this_month_earning'] = (float) ($dm->this_month_earning()->sum('delivery_charge') + $dm->this_month_earning()->sum('dm_tips'));
        unset($dm['orders']);
        unset($dm['rating']);
        unset($dm['todaysorders']);
        unset($dm['this_week_orders']);
        unset($dm['wallet']);
        return response()->json($dm, 200);
    }

    public function update_profile(Request $request)
    {
        $dm = DeliveryMan::with(['rating'])->where(['auth_token' => $request['token']])->first();
        $validator = Validator::make($request->all(), [
            'f_name' => 'required',
            'l_name' => 'required',
            'email' => 'required|unique:delivery_men,email,' . $dm->id,
            'password' => 'nullable|min:6',
        ], [
            'f_name.required' => 'First name is required!',
            'l_name.required' => 'Last name is required!',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $image = $request->file('image');

        if ($request->has('image')) {
            $imageName = Helpers::update('delivery-man/', $dm->image, 'png', $request->file('image'));
        } else {
            $imageName = $dm->image;
        }

        if ($request['password'] != null && strlen($request['password']) > 5) {
            $pass = bcrypt($request['password']);
        } else {
            $pass = $dm->password;
        }
        $dm->f_name = $request->f_name;
        $dm->l_name = $request->l_name;
        $dm->email = $request->email;
        $dm->image = $imageName;
        $dm->password = $pass;
        $dm->updated_at = now();
        $dm->save();

        if ($dm->userinfo) {
            $userinfo = $dm->userinfo;
            $userinfo->f_name = $request->f_name;
            $userinfo->l_name = $request->l_name;
            $userinfo->email = $request->email;
            $userinfo->image = $imageName;
            $userinfo->save();
        }
        return response()->json(['message' => 'successfully updated!'], 200);
    }

    public function activeStatus(Request $request)
    {
        info("AWT-ActiveStatusFunction Called");
        $dm = DeliveryMan::with(['rating'])->where(['auth_token' => $request['token']])->first();
        $dm->active = $dm->active ? 0 : 1;
        $dm->save();

        /*$custTimeStampAdd = new Awtdeliverymantimestamp();
        $custTimeStampAdd->dmts_d_id =$dm->id;
        $custTimeStampAdd->dmts_time=date('d-m-Y h:i:s');
        $custTimeStampAdd->dmts_status=$dm->active;
        $custTimeStampAdd->save();*/


        Helpers::set_time_log($dm->id, date('Y-m-d'), ($dm->active ? now() : null), ($dm->active ? null : now()));
        return response()->json(['message' => translate('messages.active_status_updated')], 200);
    }


    public function get_current_orders(Request $request)
    {
        $dm = DeliveryMan::where('auth_token', $request['token'])->first();

        if (!$dm) {
            return response()->json(['error' => 'Invalid token or delivery man not found.'], 401);
        }

        $orders = Order::with(['customer', 'restaurant'])
            ->whereIn('order_status', ['accepted', 'confirmed', 'pending', 'processing', 'picked_up', 'handover'])
            ->where('delivery_man_id', $dm->id)
            ->orderBy('accepted')
            ->orderBy('created_at', 'DESC')
            ->Notpos()
            ->get();

        // Filter based on strict mapping:
        // - general ➝ zone_wise only
        // - subscribed ➝ restaurant_wise only
        $orders = $orders->filter(function ($order) use ($dm) {
            $restaurantType = $order->restaurant->restaurant_type ?? null;
            $dmType = $dm->type;

            if ($restaurantType === 'general' && $dmType === 'zone_wise') {
                return true;
            }

            if ($restaurantType === 'subscribed' && $dmType === 'restaurant_wise') {
                return true;
            }

            return false; // All other mismatches rejected
        });

        // Add computed order details
        foreach ($orders as $value) {
            $order_detail = OrderDetail::where('order_id', $value->id)->get();
            $price = 0;
            $discount_on_food = 0;

            foreach ($order_detail as $detail) {
                $price += $detail->price * $detail->quantity;
                $discount_on_food += $detail->discount_on_food * $detail->quantity;
            }

            $value->order_details = [
                'price' => $price,
                'tax_amount' => $value->total_tax_amount,
                'discount_on_food' => $discount_on_food,
            ];
        }

        $orders = Helpers::order_data_formatting($orders->values(), true);

        return response()->json($orders, 200);
    }

    public function awt_get_latest_orders(Request $request)
    {
        $dm = DeliveryMan::where('auth_token', $request['token'])->first();

        if (!$dm) {
            return response()->json(['error' => 'Invalid token or delivery man not found.'], 401);
        }

        $orders = Order::with(['customer', 'restaurant']);

        if ($dm->type == 'zone_wise') {
            $orders = $orders->whereHas('restaurant', function ($q) use ($dm) {
                $q->where('zone_id', $dm->zone_id)
                    ->where('self_delivery_system', 0);
            });
        } else {
            $orders = $orders->where('restaurant_id', $dm->restaurant_id);
        }

        if (config('order_confirmation_model') == 'deliveryman' && $dm->type == 'zone_wise') {
            $orders = $orders->whereIn('order_status', ['pending', 'confirmed', 'processing', 'handover']);
        } else {
            $orders = $orders->whereIn('order_status', ['confirmed', 'processing', 'handover']);
        }

        $orders = $orders->delivery()
            ->OrderScheduledIn(30)
            ->whereNull('delivery_man_id')
            ->orderBy('schedule_at', 'desc')
            ->Notpos()
            ->get();

        // Filter based on restaurant_type and deliveryman type
        $orders = $orders->filter(function ($order) use ($dm) {
            $restaurantType = $order->restaurant->restaurant_type ?? null;
            $dmType = $dm->type;

            return ($restaurantType === 'general' && $dmType === 'zone_wise') ||
                ($restaurantType === 'subscribed' && $dmType === 'restaurant_wise');
        });

        // Filter out orders that have a related Pidge log
        $ordersToRemove = [];
        foreach ($orders as $val) {
            $pidge_log = ServiceOrderPidgeLog::where('service_order_id', $val->id)
                ->whereIn('action_type', ['created', 'DELIVERED', 'PICKED_UP', 'OUT_FOR_PICKUP'])
                ->first();

            if ($pidge_log) {
                $ordersToRemove[] = $val->id;
            }
        }

        $orders = $orders->reject(function ($order) use ($ordersToRemove) {
            return in_array($order->id, $ordersToRemove);
        });

        $orders = Helpers::order_data_formatting($orders->values(), true);

        return response()->json($orders, 200);
    }

    public function get_latest_orders(Request $request)
    {
        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

        if (!$dm) {
            return response()->json(['error' => 'Invalid token or delivery man not found.'], 401);
        }

        $orders = Order::with(['customer', 'restaurant']);

        if ($dm->type == 'zone_wise') {
            $orders = $orders->whereHas('restaurant', function ($q) use ($dm) {
                $q->where('zone_id', $dm->zone_id)->where('self_delivery_system', 0);
            });
        } else {
            $orders = $orders->where('restaurant_id', $dm->restaurant_id);
        }

        if (config('order_confirmation_model') == 'deliveryman' && $dm->type == 'zone_wise') {
            $orders = $orders->whereIn('order_status', ['processing', 'handover']);
        } else {
            $orders = $orders->whereIn('order_status', ['processing', 'handover']);
        }

        $orders = $orders->delivery()
            ->OrderScheduledIn(30)
            ->whereNull('delivery_man_id')
            ->orderBy('schedule_at', 'desc')
            ->Notpos()
            ->get();

        // ✨ Add conditional logic here: enforce restaurant_type vs deliveryman.type
        $orders = $orders->filter(function ($order) use ($dm) {
            $restaurantType = $order->restaurant->restaurant_type ?? null;
            $dmType = $dm->type;

            return ($restaurantType === 'general' && $dmType === 'zone_wise') ||
                ($restaurantType === 'subscribed' && $dmType === 'restaurant_wise');
        });

        $orders = Helpers::order_data_formatting($orders->values(), true);

        return response()->json($orders, 200);
    }

    public function accept_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();
        $order = Order::where('id', $request['order_id'])
            // ->whereIn('order_status', ['pending', 'confirmed'])
            ->whereNull('delivery_man_id')
            ->Notpos()
            ->first();
        if (!$order) {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('messages.can_not_accept')]
                ]
            ], 404);
        }
        if ($dm->current_orders >= config('dm_maximum_orders')) {
            return response()->json([
                'errors' => [
                    ['code' => 'dm_maximum_order_exceed', 'message' => translate('messages.dm_maximum_order_exceed_warning')]
                ]
            ], 405);
        }

        $cash_in_hand = isset($dm->wallet) ? $dm->wallet->collected_cash : 0;

        $dm_max_cash = BusinessSetting::where('key', 'dm_max_cash_in_hand')->first();

        $dMLatLngDetails = DeliveryHistory::where('delivery_man_id', $dm->id)->first();
        $dMLat_var_lng = $dMLatLngDetails->longitude;
        $dMLat_var_lat = $dMLatLngDetails->latitude;
        $resLatLngDetails = Restaurant::where('id', $order->restaurant_id)->first();
        $resLat_var_lng = $resLatLngDetails->longitude;
        $resLat_var_lat = $resLatLngDetails->latitude;
        /*  $custLatLngDetails = CustomerAddress::where('user_id', $order->user_id)->first();
          $custLat_var_lng=$custLatLngDetails->longitude;
          $custLat_var_lat=$custLatLngDetails->latitude;*/

        $custAddressDetailsAwt = json_decode($order->delivery_address, true);
        $custLat_var_lat = $custAddressDetailsAwt['latitude'];
        $custLat_var_lng = $custAddressDetailsAwt['longitude'];
        // info("DELIVERY MAN - LATITUDE - ".$dMLat_var_lat);
        //info("RESTAURANT - LATITUDE - ".$resLat_var_lat);
        //info("CUSTomer - LATITUDE - ".$custLat_var_lat);

        /*--- Restaurant Lat Lng & Driver Lat Lng Distance Calculation ---*/
        $theta = $resLat_var_lng - $dMLat_var_lng;
        $dist = sin(deg2rad($resLat_var_lat)) * sin(deg2rad($dMLat_var_lat)) + cos(deg2rad($resLat_var_lat)) * cos(deg2rad($dMLat_var_lat)) * cos(deg2rad($theta));
        $dist = acos($dist);
        $dist = rad2deg($dist);
        $miles = $dist * 60 * 1.1515;
        //$unit = strtoupper($unit);
        $awtDist1 = $miles * 1.609344; //kms
        $awtDistInMeter_location1 = 1000 * $awtDist1; // kms to meter
        /**/

        /*------------------ Restaurant Lat Lng & Customer Lat Lng Distance Calculation ------------------*/
        $theta1 = $resLat_var_lng - $custLat_var_lng;
        $dist1 = sin(deg2rad($resLat_var_lat)) * sin(deg2rad($custLat_var_lat)) + cos(deg2rad($resLat_var_lat)) * cos(deg2rad($custLat_var_lat)) * cos(deg2rad($theta1));
        $dist1 = acos($dist1);
        $dist1 = rad2deg($dist1);
        $miles1 = $dist1 * 60 * 1.1515;
        //$unit = strtoupper($unit);

        $awtDist2 = $miles1 * 1.609344;
        $awtDistInMeter_location2 = 1000 * $awtDist2;
        //  info("Restaurant Lat Long - ".$resLat_var_lat." --- ".$resLat_var_lng." Customer Lat Long = ".$custLat_var_lat." --- ".$custLat_var_lng." ----- total kms ------- ".$awtDist2." ----- Meters --------".$awtDistInMeter_location2);
        /**/

        $totAwtDist = $awtDistInMeter_location1 + $awtDistInMeter_location2;
        //$totAwtDist = $awtDistInMeter_location2;
        $roundDis_totAwtDist = round($totAwtDist);
        $flagDistAwt1_fare = 23;
        if ($roundDis_totAwtDist <= 3000) {
            $driverAwtFarePrice = 23;
        } else if ($roundDis_totAwtDist > 3000 && $roundDis_totAwtDist <= 10000) {
            $tempDist = $roundDis_totAwtDist - 3000;

            $tempDistanceFlag = $tempDist / 200;

            $tempDistanceFlag = 1 * round($tempDistanceFlag);

            $driverAwtFarePrice = 23 + $tempDistanceFlag;
        } else {
            $driverAwtFarePrice = 60;
        }


        $value = $dm_max_cash ? $dm_max_cash->value : 0;

        if ($order->payment_method == "cash_on_delivery" && (($cash_in_hand + $order->order_amount) >= $value)) {
            return response()->json(['errors' => Helpers::error_formater('dm_max_cash_in_hand', translate('delivery man max cash in hand exceeds'))], 203);
        }

        $order->order_status = in_array($order->order_status, ['pending', 'confirmed']) ? 'accepted' : $order->order_status;
        $order->delivery_man_id = $dm->id;
        $order->accepted = now();
        $order->awt_dm_tot_dis = $roundDis_totAwtDist;
        $order->awt_dm_tot_fare = $driverAwtFarePrice;
        $order->save();
        $dm->current_orders = $dm->current_orders + 1;
        $dm->save();




        $dm->increment('assigned_order_count');

        $fcm_token = $order->customer->cm_firebase_token;

        $data_res = [
            'title' => translate('messages.order_push_title'),
            'description' => 'Order Accepted by the Delivery Man',
            'order_id' => $order['id'],
            'image' => '',
            'type' => 'order_status'
        ];

        Helpers::send_push_notif_to_device($order->restaurant->vendor->firebase_token, $data_res);

        $value = Helpers::order_status_update_message('accepted');
        try {
            if ($value) {
                $data = [
                    'title' => translate('messages.order_push_title'),
                    'description' => $value,
                    'order_id' => $order['id'],
                    'image' => '',
                    'type' => 'order_status'
                ];
                Helpers::send_push_notif_to_device($fcm_token, $data);
            }

        } catch (\Exception $e) {

        }

        return response()->json(['message' => 'Order accepted successfully'], 200);

    }

    public function record_location_data(Request $request)
    {
        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();
        DB::table('delivery_histories')->insert([
            'delivery_man_id' => $dm['id'],
            'longitude' => $request['longitude'],
            'latitude' => $request['latitude'],
            'time' => now(),
            'location' => $request['location'],
            'created_at' => now(),
            'updated_at' => now()
        ]);
        return response()->json(['message' => 'location recorded'], 200);
    }

    public function get_order_history(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

        $history = DeliveryHistory::where(['order_id' => $request['order_id'], 'delivery_man_id' => $dm['id']])->get();
        return response()->json($history, 200);
    }

    public function awt_accept_return_order_request(Request $request)
    {
        $orderId = $request->order_id;
        $dmId = $request->dm_id;
        $order = Order::where(['id' => $orderId, 'delivery_man_id' => $dmId])->Notpos()->first();
        $order->order_status = "canceled";
        // $order[$request['status']] = now();
        $order->save();
        return response()->json(['message' => 'Return Order Completed. Thank you'], 200);
    }

    public function awt_return_order_request(Request $request)
    {
        $orderId = $request->order_id;
        $reasonVal = $request->reason;
        $dmId = $request->dm_id;
        $order = Order::where(['id' => $orderId, 'delivery_man_id' => $dmId])->Notpos()->first();
        $order->awt_return_order_reason = $reasonVal;
        // $order[$request['status']] = now();
        $order->save();
        return response()->json(['message' => 'Return Order Requested, Wait for Approval'], 200);
    }

    public function update_order_status(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'status' => 'required|in:confirmed,canceled,picked_up,delivered',
        ]);

        $validator->sometimes('otp', 'required', function ($request) {
            return (Config::get('order_delivery_verification') == 1 && $request['status'] == 'delivered');
        });

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

        $order = Order::where(['id' => $request['order_id'], 'delivery_man_id' => $dm['id']])->Notpos()->first();

        if ($request['status'] == "confirmed" && config('order_confirmation_model') == 'restaurant') {
            return response()->json([
                'errors' => [
                    ['code' => 'order-confirmation-model', 'message' => translate('messages.order_confirmation_warning')]
                ]
            ], 403);
        }

        if ($request['status'] == 'canceled' && !config('canceled_by_deliveryman')) {
            return response()->json([
                'errors' => [
                    ['code' => 'status', 'message' => translate('messages.you_can_not_cancel_a_order')]
                ]
            ], 403);
        }

        if ($order->confirmed && $request['status'] == 'canceled') {
            return response()->json([
                'errors' => [
                    ['code' => 'delivery-man', 'message' => translate('messages.order_can_not_cancle_after_confirm')]
                ]
            ], 403);
        }

        if (Config::get('order_delivery_verification') == 1 && $request['status'] == 'delivered' && $order->otp != $request['otp']) {
            return response()->json([
                'errors' => [
                    ['code' => 'otp', 'message' => 'Not matched']
                ]
            ], 406);
        }
        if ($request->status == 'delivered') {
            // For Refer and Earn Concept
            $user_data_checking = User::where('id', $order->user_id)->first();
            if ($user_data_checking->referred_by != '') {
                $previous_completed_orders = Order::where('user_id', $user_data_checking->id)->where('order_status', 'delivered')->get();
                if (count($previous_completed_orders) == 0) {
                    $referrer_earning = BusinessSetting::where('key', 'ref_earning_exchange_rate')
                        ->value('value');
                    $referrer_wallet_transaction = CustomerLogic::create_wallet_transaction($user_data_checking->referred_by, $referrer_earning, 'add_fund', 'Referral Earning');

                    $firebase_token = User::where('id', $user_data_checking->referred_by)->value('cm_firebase_token');
                    $data = [
                        'title' => 'Referal Earning',
                        'description' => 'Added a Referral Earning of Rs.' . $referrer_earning . ' to your Wallet',
                        'order_id' => $order['id'],
                        'image' => '',
                        'type' => 'order_status',
                    ];
                    Helpers::send_push_notif_to_device($firebase_token, $data);
                }
            }

            if ($order->transaction == null) {
                $reveived_by = $order->payment_method == 'cash_on_delivery' ? ($dm->type != 'zone_wise' ? 'restaurant' : 'deliveryman') : 'admin';

                if (OrderLogic::create_transaction($order, $reveived_by, null)) {
                    $order->payment_status = 'paid';
                } else {
                    return response()->json([
                        'errors' => [
                            ['code' => 'error', 'message' => translate('messages.faield_to_create_order_transaction')]
                        ]
                    ], 406);
                }
            }
            if ($order->transaction) {
                $order->transaction->update(['delivery_man_id' => $dm->id]);
            }

            $order->details->each(function ($item, $key) {
                if ($item->food) {
                    $item->food->increment('order_count');
                }
            });
            $order->customer->increment('order_count');

            $dm->current_orders = $dm->current_orders > 1 ? $dm->current_orders - 1 : 0;
            $dm->save();

            $dm->increment('order_count');
            $order->restaurant->increment('order_count');
            Helpers::petpoojaRiderStatusUpdate($order->id, 'delivered');
        } else if ($request->status == 'canceled') {
            if ($order->delivery_man) {
                $dm = $order->delivery_man;
                $dm->current_orders = $dm->current_orders > 1 ? $dm->current_orders - 1 : 0;
                $dm->save();
            }
        }
        if ($request['status'] == 'picked_up') {
            Helpers::petpoojaRiderStatusUpdate($order->id, 'pickedup');
        }

        $order->order_status = $request['status'];
        $order[$request['status']] = now();
        $order->save();

        Helpers::send_order_notification($order);

        $order_id = $order['id'];
        $user_id = $order['user_id'];
        $awt_status = $order['order_status'];
        $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status);

        return response()->json(['message' => 'Status updated'], 200);
    }

    public function get_order_details(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

        $order = Order::with(['details'])->where(['delivery_man_id' => $dm['id'], 'id' => $request['order_id']])->Notpos()->first();
        // dd($order->awt_delivery_notes);exit;
        if (!$order) {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('messages.not_found')]
                ]
            ], 404);
        }
        //awt_delivery_notes
        $details = Helpers::awt_order_details_data_formatting($order->details, $order->awt_delivery_notes);
        // dd($details);exit;
        return response()->json($details, 200);
    }

    public function get_order_details_before_accept(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

        $order = Order::with(['details'])->where('id', $request['order_id'])->Notpos()->first();
        // dd($order->awt_delivery_notes);exit;
        if (!$order) {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('messages.not_found')]
                ]
            ], 404);
        }
        //awt_delivery_notes
        $details = Helpers::awt_order_details_data_formatting($order->details, $order->awt_delivery_notes);
        // dd($details);exit;
        return response()->json($details, 200);
    }

    public function get_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

        $order = Order::with(['customer', 'restaurant', 'details'])->where(['delivery_man_id' => $dm['id'], 'id' => $request['order_id']])->Notpos()->first();
        if (!$order) {
            return response()->json([
                'errors' => [
                    ['code' => 'order', 'message' => translate('messages.not_found')]
                ]
            ], 204);
        }
        return response()->json(Helpers::order_data_formatting($order), 200);
    }

    public function get_all_orders(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

        $paginator = Order::with(['customer', 'restaurant'])
            ->where(['delivery_man_id' => $dm['id']])
            ->whereIn('order_status', ['delivered', 'canceled', 'refund_requested', 'refunded', 'failed'])
            ->orderBy('schedule_at', 'desc')
            ->Notpos()
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);

        foreach ($paginator as $value) {
            $order_detail = OrderDetail::where('order_id', $value->id)->get();
            $total = 0;
            $tax = 0;
            $discount = 0;
            foreach ($order_detail as $detail) {
                $total_with_quantity = $detail->price * $detail->quantity;
                $price = $total + $total_with_quantity;
                $discount_with_quantity = $detail->discount_on_food * $detail->quantity;
                $discount_on_food = $discount + $discount_with_quantity;
            }
            $value->order_details = array(
                'price' => $price,
                'tax_amount' => $value->total_tax_amount,
                'discount_on_food' => $discount_on_food
            );
        }

        $orders = Helpers::order_data_formatting($paginator->items(), true);

        $data = [
            'total_size' => $paginator->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'orders' => $orders
        ];

        // dd($data);exit;
        return response()->json($data, 200);
    }

    public function get_last_location(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $last_data = DeliveryHistory::whereHas('delivery_man.orders', function ($query) use ($request) {
            return $query->where('id', $request->order_id);
        })->latest()->first();
        return response()->json($last_data, 200);
    }

    public function order_payment_status_update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'status' => 'required|in:paid'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

        if (Order::where(['delivery_man_id' => $dm['id'], 'id' => $request['order_id']])->Notpos()->first()) {
            Order::where(['delivery_man_id' => $dm['id'], 'id' => $request['order_id']])->update([
                'payment_status' => $request['status']
            ]);
            return response()->json(['message' => 'Payment status updated'], 200);
        }
        return response()->json([
            'errors' => [
                ['code' => 'order', 'message' => 'not found!']
            ]
        ], 404);
    }

    public function update_fcm_token(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fcm_token' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

        DeliveryMan::where(['id' => $dm['id']])->update([
            'fcm_token' => $request['fcm_token']
        ]);

        return response()->json(['message' => 'successfully updated!'], 200);
    }

    public function get_notifications(Request $request)
    {

        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

        $notifications = Notification::active()->where(function ($q) use ($dm) {
            $q->whereNull('zone_id')->orWhere('zone_id', $dm->zone_id);
        })->where('tergat', 'deliveryman')->where('created_at', '>=', \Carbon\Carbon::today()->subDays(7))->get();

        $user_notifications = UserNotification::where('delivery_man_id', $dm->id)->where('created_at', '>=', \Carbon\Carbon::today()->subDays(7))->get();

        $notifications->append('data');

        $notifications = $notifications->merge($user_notifications);
        try {
            return response()->json($notifications, 200);
        } catch (\Exception $e) {
            return response()->json([], 200);
        }
    }

    public function remove_account(Request $request)
    {
        $dm = DeliveryMan::where(['auth_token' => $request['token']])->first();

        if (Order::where('delivery_man_id', $dm->id)->whereIn('order_status', ['pending', 'accepted', 'confirmed', 'processing', 'handover', 'picked_up'])->count()) {
            return response()->json(['errors' => [['code' => 'on-going', 'message' => translate('messages.user_account_delete_warning')]]], 203);
        }

        if ($dm->wallet && $dm->wallet->collected_cash > 0) {
            return response()->json(['errors' => [['code' => 'on-going', 'message' => translate('messages.user_account_wallet_delete_warning')]]], 203);
        }

        if (Storage::disk('public')->exists('delivery-man/' . $dm['image'])) {
            Storage::disk('public')->delete('delivery-man/' . $dm['image']);
        }

        foreach (json_decode($dm['identity_image'], true) as $img) {
            if (Storage::disk('public')->exists('delivery-man/' . $img)) {
                Storage::disk('public')->delete('delivery-man/' . $img);
            }
        }
        if ($dm->userinfo) {
            $dm->userinfo->delete();
        }

        $dm->delete();
        return response()->json([]);
    }

}