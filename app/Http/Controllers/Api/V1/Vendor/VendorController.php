<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use App\CentralLogics\RestaurantLogic;
use App\Http\Controllers\Controller;
use App\Models\DeliveryConfig;
use App\Models\Vendor;
use App\Models\Order;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\Campaign;
use App\Models\WithdrawRequest;
use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Rap2hpoutre\FastExcel\FastExcel;

class VendorController extends Controller
{
    public function get_profile(Request $request)
    {
        $vendor = $request['vendor'];
        $restaurant = Helpers::restaurant_data_formatting($vendor->restaurants[0], false);
        $discount = Helpers::get_restaurant_discount($vendor->restaurants[0]);
        unset($restaurant['discount']);
        $restaurant['discount'] = $discount;
        $restaurant['schedules'] = $restaurant->schedules()->get();

        $vendor['order_count'] = $vendor->orders->count();
        $vendor['todays_order_count'] = $vendor->todaysorders->count();
        $vendor['this_week_order_count'] = $vendor->this_week_orders->count();
        $vendor['this_month_order_count'] = $vendor->this_month_orders->count();
        $vendor['member_since_days'] = $vendor->created_at->diffInDays();
        $vendor['cash_in_hands'] = $vendor->wallet ? (float) $vendor->wallet->collected_cash : 0;
        $vendor['balance'] = $vendor->wallet ? (float) $vendor->wallet->balance : 0;
        $vendor['total_earning'] = $vendor->wallet ? (float) $vendor->wallet->total_earning : 0;
        $vendor['todays_earning'] = (float) $vendor->todays_earning()->sum('restaurant_amount');
        $vendor['this_week_earning'] = (float) $vendor->this_week_earning()->sum('restaurant_amount');
        $vendor['this_month_earning'] = (float) $vendor->this_month_earning()->sum('restaurant_amount');
        $vendor["restaurants"] = $restaurant;
        $vendor['userinfo'] = $vendor->userinfo;
        unset($vendor['orders']);
        unset($vendor['rating']);
        unset($vendor['todaysorders']);
        unset($vendor['this_week_orders']);
        unset($vendor['wallet']);
        unset($vendor['todaysorders']);
        unset($vendor['this_week_orders']);
        unset($vendor['this_month_orders']);

        return response()->json($vendor, 200);
    }

    public function active_status(Request $request)
    {
        $restaurant = $request->vendor->restaurants[0];
        $restaurant->active = $restaurant->active ? 0 : 1;
        $restaurant->save();
        return response()->json(['message' => $restaurant->active ? translate('messages.restaurant_opened') : translate('messages.restaurant_temporarily_closed')], 200);
    }

    public function get_earning_data(Request $request)
    {
        $vendor = $request['vendor'];
        $data = RestaurantLogic::get_earning_data($vendor->id);
        return response()->json($data, 200);
    }

    public function update_profile(Request $request)
    {
        $vendor = $request['vendor'];
        $validator = Validator::make($request->all(), [
            'f_name' => 'required',
            'l_name' => 'required',
            'phone' => 'required|unique:vendors,phone,' . $vendor->id,
            'password' => 'nullable|min:6',
        ], [
            'f_name.required' => translate('messages.first_name_is_required'),
            'l_name.required' => translate('messages.Last name is required!'),
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if ($request->has('image')) {
            $imageName = Helpers::update('vendor/', $vendor->image, 'png', $request->file('image'));
        } else {
            $imageName = $vendor->image;
        }

        if ($request['password'] != null && strlen($request['password']) > 5) {
            $pass = bcrypt($request['password']);
        } else {
            $pass = $vendor->password;
        }
        $vendor->f_name = $request->f_name;
        $vendor->l_name = $request->l_name;
        $vendor->phone = $request->phone;
        $vendor->image = $imageName;
        $vendor->password = $pass;
        $vendor->updated_at = now();
        $vendor->save();

        // if($vendor->userinfo) {
        //     $userinfo = $vendor->userinfo;
        //     $userinfo->f_name = $request->f_name;
        //     $userinfo->l_name = $request->l_name;
        //     $userinfo->email = $request->email;
        //     $userinfo->image = $imageName;
        //     $userinfo->save();
        // }

        return response()->json(['message' => translate('messages.profile_updated_successfully')], 200);
    }

    public function get_awt_current_orders(Request $request)
    {
        $vendor = $request['vendor'];

        $orders = Order::whereHas('restaurant.vendor', function ($query) use ($vendor) {
            $query->where('id', $vendor->id);
        })
            ->with('customer')

            ->where(function ($query) use ($vendor) {
                if (config('order_confirmation_model') == 'restaurant' || $vendor->restaurants[0]->self_delivery_system) {
                    $query->whereIn('order_status', ['accepted', 'pending', 'confirmed', 'processing', 'handover', 'picked_up']);
                } else {
                    $query->whereIn('order_status', ['confirmed', 'processing', 'handover', 'picked_up'])
                        ->orWhere(function ($query) {
                            $query->where('payment_status', 'paid')->where('order_status', 'accepted');
                        })
                        ->orWhere(function ($query) {
                            $query->where('order_status', 'pending')->where('order_type', 'take_away');
                        })->orWhere(function ($query) {
                            $query->where('awt_return_order_reason', '!=', 'n/a');
                        });
                }
            })
            ->Notpos()
            ->orderBy('created_at', 'DESC')
            ->get();
        $orders = Helpers::awt_order_data_formatting($orders, true);
        return response()->json($orders, 200);
    }

    public function get_current_orders(Request $request)
    {
        $vendor = $request['vendor'];

        $orders = Order::whereHas('restaurant.vendor', function ($query) use ($vendor) {
            $query->where('id', $vendor->id);
        })
            ->with('customer')

            ->where(function ($query) use ($vendor) {
                if (config('order_confirmation_model') == 'restaurant' || $vendor->restaurants[0]->self_delivery_system) {
                    $query->whereIn('order_status', ['accepted', 'pending', 'confirmed', 'processing', 'handover', 'picked_up']);
                } else {
                    $query->whereIn('order_status', ['confirmed', 'processing', 'handover', 'picked_up'])
                        ->orWhere(function ($query) {
                            $query->where('payment_status', 'paid')->where('order_status', 'accepted');
                        })
                        ->orWhere(function ($query) {
                            $query->where('order_status', 'pending')->where('order_type', 'take_away');
                        });
                }
            })
            ->Notpos()
            ->orderBy('schedule_at', 'desc')
            ->get();
        $orders = Helpers::order_data_formatting($orders, true);
        return response()->json($orders, 200);
    }

    public function get_completed_orders(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'required',
            'offset' => 'required',
            'status' => 'required|in:all,refunded,delivered',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $vendor = $request['vendor'];

        $paginator = Order::whereHas('restaurant.vendor', function ($query) use ($vendor) {
            $query->where('id', $vendor->id);
        })
            ->with('customer')
            ->when($request->status == 'all', function ($query) {
                return $query->whereIn('order_status', ['refunded', 'delivered']);
            })
            ->when($request->status != 'all', function ($query) use ($request) {
                return $query->where('order_status', $request->status);
            })
            ->Notpos()
            ->latest()
            ->paginate($request['limit'], ['*'], 'page', $request['offset']);
        foreach ($paginator as $value) {
            $value->schedule_at = date('h:i A, d M Y', strtotime($value->schedule_at));
        }
        $orders = Helpers::order_data_formatting($paginator->items(), true);
        $data = [
            'total_size' => $paginator->total(),
            'limit' => $request['limit'],
            'offset' => $request['offset'],
            'orders' => $orders
        ];
        return response()->json($data, 200);
    }

    public function awt_order_auto_assign_cron_job(Request $request)
    {
        // $order = Order::where(['order_status', 'processing']);
        //,['is_delivery_call_done','0']
        dd($request->all());
        exit;
    }

    public function accept_return_order(Request $request)
    {
        // dd($request->order_id);exit;
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
            //'status' => 'required|in:canceled'
        ]);

        $vendor = $request['vendor'];

        /*$order = Order::whereHas('restaurant.vendor', function($query) use($vendor){
            $query->where('id', $vendor->id);
        })
        ->where('id', $request['order_id'])
        ->Notpos()
        ->first();*/

        $order = Order::where('id', $request['order_id'])
            ->Notpos()
            ->first();

        $order->awt_return_order_status = 1;
        $order->save();

        return response()->json(['message' => 'Status updated'], 200);
    }

    public function update_order_status(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required',
            'status' => 'required|in:confirmed,processing,handover,delivered,canceled'
        ]);

        $validator->sometimes('otp', 'required', function ($request) {
            return (Config::get('order_delivery_verification') == 1 && $request['status'] == 'delivered');
        });

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $vendor = $request['vendor'];

        $order = Order::whereHas('restaurant.vendor', function ($query) use ($vendor) {
            $query->where('id', $vendor->id);
        })
            ->where('id', $request['order_id'])
            ->Notpos()
            ->first();

        if ($request['order_status'] == 'canceled') {
            if (!config('canceled_by_restaurant')) {
                return response()->json([
                    'errors' => [
                        ['code' => 'status', 'message' => translate('messages.you_can_not_cancel_a_order')]
                    ]
                ], 403);
            } else if ($order->confirmed) {
                return response()->json([
                    'errors' => [
                        ['code' => 'status', 'message' => translate('messages.you_can_not_cancel_after_confirm')]
                    ]
                ], 403);
            }
        }

        if ($request['status'] == "confirmed" && !$vendor->restaurants[0]->self_delivery_system && config('order_confirmation_model') == 'deliveryman' && $order->order_type != 'take_away') {
            return response()->json([
                'errors' => [
                    ['code' => 'order-confirmation-model', 'message' => translate('messages.order_confirmation_warning')]
                ]
            ], 403);
        }

        if ($order->picked_up != null) {
            return response()->json([
                'errors' => [
                    ['code' => 'status', 'message' => translate('messages.You_can_not_change_status_after_picked_up_by_delivery_man')]
                ]
            ], 403);
        }

        if ($request['status'] == 'delivered' && $order->order_type != 'take_away' && !$vendor->restaurants[0]->self_delivery_system) {
            return response()->json([
                'errors' => [
                    ['code' => 'status', 'message' => translate('messages.you_can_not_delivered_delivery_order')]
                ]
            ], 403);
        }
        if (Config::get('order_delivery_verification') == 1 && $request['status'] == 'delivered' && $order->otp != $request['otp']) {
            return response()->json([
                'errors' => [
                    ['code' => 'otp', 'message' => 'Not matched']
                ]
            ], 403);
        }

        if ($request->status == 'delivered' && $order->transaction == null) {
            if ($order->payment_method == 'cash_on_delivery') {
                $ol = OrderLogic::create_transaction($order, 'restaurant', null);
            } else {
                $ol = OrderLogic::create_transaction($order, 'admin', null);
            }

            if (!$ol) {
                return response()->json([
                    'errors' => [
                        ['code' => 'error', 'message' => translate('messages.faield_to_create_order_transaction')]
                    ]
                ], 406);
            }

            $order->payment_status = 'paid';
        }

        if ($request->status == 'delivered') {
            $order->details->each(function ($item, $key) {
                if ($item->food) {
                    $item->food->increment('order_count');
                }
            });
            $order->customer->increment('order_count');
            $order->restaurant->increment('order_count');
        }

        if ($request->status == 'canceled' || $request->status == 'delivered') {
            if ($order->delivery_man) {
                $dm = $order->delivery_man;
                $dm->current_orders = $dm->current_orders > 1 ? $dm->current_orders - 1 : 0;
                $dm->save();
            }
        }
        if ($request->status == 'processing') {
            $order->processing_time = isset($request->processing_time) ? $request->processing_time : explode('-', $order['restaurant']['delivery_time'])[0];
        }
        $order->order_status = $request['status'];
        $order[$request['status']] = now();

        if ($request['status'] == 'confirmed') {
            $order->deliveryman_notification_sending_time = date('Y-m-d H:i:s');
            // Retrieve the delivery configuration based on the 'priority'
            $deliveryConfig = DeliveryConfig::getPriorityChannel();
            if ($deliveryConfig) {
                if ($deliveryConfig->delivery_channel === 'Pidge') {
                    $order->delivery_partner = "Pidge";
                    // Call the Pidge model method
                    Helpers::sendPidgeNotification($order);
                } elseif ($deliveryConfig->delivery_channel === 'Owned Delivery Persons') {
                    $order->delivery_partner = "Owned Delivery Persons";
                    // Call the Helpers::send_order_notification method
                    Helpers::send_order_notification($order);
                }
            } else {
                Helpers::send_order_notification($order);
            }
        } else {
            Helpers::send_order_notification($order);
        }
        $order->save();

        $order_id = $order['id'];
        $user_id = $order['user_id'];
        $awt_status = $order['order_status'];
        $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status);

        //  Helpers:awt_order_custom_status_push_notification();

        return response()->json(['message' => 'Status updated'], 200);
    }

    public function awt_order_push_notification_driver(Request $request)
    {


        $orderId = $request->order_id;
        //dd($orderId);
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

    public function awt_get_order_details(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $vendor = $request['vendor'];

        $order = Order::whereHas('restaurant.vendor', function ($query) use ($vendor) {
            $query->where('id', $vendor->id);
        })
            ->with(['customer', 'details', 'delivery_man'])
            ->where('id', $request['order_id'])
            ->Notpos()
            ->first();
        //dd($order['awt_return_order_reason']);exit;
        if ($order['awt_return_order_reason'] != "n/a") {
            $order['order_status'] = "awt-return";
        }
        $details = $order->details;
        $order['details'] = Helpers::order_details_data_formatting($details);
        return response()->json(['order' => $order], 200);
    }

    public function get_order_details(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $vendor = $request['vendor'];

        $order = Order::whereHas('restaurant.vendor', function ($query) use ($vendor) {
            $query->where('id', $vendor->id);
        })
            ->with(['customer', 'details', 'delivery_man'])
            ->where('id', $request['order_id'])
            ->Notpos()
            ->first();
        $details = $order->details;
        $order['details'] = Helpers::order_details_data_formatting($details);
        return response()->json(['order' => $order], 200);
    }

    public function awt_get_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $vendor = $request['vendor'];

        $order = Order::whereHas('restaurant.vendor', function ($query) use ($vendor) {
            $query->where('id', $vendor->id);
        })
            ->with(['customer', 'details', 'delivery_man'])
            ->where('id', $request['order_id'])
            ->Notpos()
            ->first();

        //dd($order->order_status);exit;

        if ($order->awt_return_order_reason != "n/a") {
            $order->order_status = "awt-return";
        }

        return response()->json(Helpers::order_data_formatting($order), 200);
    }

    public function get_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $vendor = $request['vendor'];

        $order = Order::whereHas('restaurant.vendor', function ($query) use ($vendor) {
            $query->where('id', $vendor->id);
        })
            ->with(['customer', 'details', 'delivery_man'])
            ->where('id', $request['order_id'])
            ->Notpos()
            ->first();

        return response()->json(Helpers::order_data_formatting($order), 200);
    }

    public function get_all_orders(Request $request)
    {
        $vendor = $request['vendor'];

        $orders = Order::whereHas('restaurant.vendor', function ($query) use ($vendor) {
            $query->where('id', $vendor->id);
        })
            ->with('customer')
            ->Notpos()
            ->orderBy('schedule_at', 'desc')
            ->get();
        //dd($orders->toArray());
        $orders = Helpers::order_data_formatting($orders, true);
        return response()->json($orders, 200);
    }

    public function get_awt_all_orders(Request $request)
    {
        $vendor = $request['vendor'];

        $orders = Order::whereHas('restaurant.vendor', function ($query) use ($vendor) {
            $query->where('id', $vendor->id);
        })
            ->with('customer')
            ->Notpos()
            ->orderBy('schedule_at', 'desc')
            ->get();
        //dd($orders->toArray());
        // $orders= Helpers::order_data_formatting($orders, true);
        $orders = Helpers::awt_order_data_formatting($orders, true);
        return response()->json($orders, 200);
    }

    public function update_fcm_token(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fcm_token' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $vendor = $request['vendor'];

        Vendor::where(['id' => $vendor['id']])->update([
            'firebase_token' => $request['fcm_token']
        ]);

        return response()->json(['message' => 'successfully updated!'], 200);
    }

    public function get_notifications(Request $request)
    {
        $vendor = $request['vendor'];

        $notifications = Notification::active()->where(function ($q) use ($vendor) {
            $q->whereNull('zone_id')->orWhere('zone_id', $vendor->restaurants[0]->zone_id);
        })->where('tergat', 'restaurant')->where('created_at', '>=', \Carbon\Carbon::today()->subDays(7))->get();

        $notifications->append('data');

        $user_notifications = UserNotification::where('vendor_id', $vendor->id)->where('created_at', '>=', \Carbon\Carbon::today()->subDays(7))->get();


        $notifications = $notifications->merge($user_notifications);

        // exit;
        try {
            return response()->json($notifications, 200);
        } catch (\Exception $e) {
            return response()->json([], 200);
        }
    }

    public function get_basic_campaigns(Request $request)
    {
        $vendor = $request['vendor'];
        $campaigns = Campaign::with('restaurants')->latest()->get();
        $data = [];
        $restaurant_id = $vendor->restaurants[0]->id;
        foreach ($campaigns as $item) {
            $variations = [];
            $restaurant_ids = count($item->restaurants) ? $item->restaurants->pluck('id')->toArray() : [];
            if ($item->start_date) {
                $item['available_date_starts'] = $item->start_date->format('Y-m-d');
                unset($item['start_date']);
            }
            if ($item->end_date) {
                $item['available_date_ends'] = $item->end_date->format('Y-m-d');
                unset($item['end_date']);
            }

            if (count($item['translations']) > 0) {
                $translate = array_column($item['translations']->toArray(), 'value', 'key');
                $item['title'] = $translate['title'];
                $item['description'] = $translate['description'];
            }

            $item['is_joined'] = in_array($restaurant_id, $restaurant_ids) ? true : false;
            unset($item['restaurants']);
            array_push($data, $item);
        }
        // $data = CampaignLogic::get_basic_campaigns($vendor->restaurants[0]->id, $request['limite'], $request['offset']);
        return response()->json($data, 200);
    }

    public function remove_restaurant(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'campaign_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $campaign = Campaign::where('status', 1)->find($request->campaign_id);
        if (!$campaign) {
            return response()->json([
                'errors' => [
                    ['code' => 'campaign', 'message' => 'Campaign not found or upavailable!']
                ]
            ]);
        }
        $restaurant = $request['vendor']->restaurants[0];
        $campaign->restaurants()->detach($restaurant);
        $campaign->save();
        return response()->json(['message' => translate('messages.you_are_successfully_removed_from_the_campaign')], 200);
    }
    public function addrestaurant(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'campaign_id' => 'required'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $campaign = Campaign::where('status', 1)->find($request->campaign_id);
        if (!$campaign) {
            return response()->json([
                'errors' => [
                    ['code' => 'campaign', 'message' => 'Campaign not found or upavailable!']
                ]
            ]);
        }
        $restaurant = $request['vendor']->restaurants[0];
        $campaign->restaurants()->attach($restaurant);
        $campaign->save();
        return response()->json(['message' => translate('messages.you_are_successfully_joined_to_the_campaign')], 200);
    }

    public function get_products(Request $request)
    {
        $limit = $request->limit ? $request->limit : 25;
        $offset = $request->offset ? $request->offset : 1;

        $type = $request->query('type', 'all');

        $paginator = Food::withoutGlobalScope('translate')->type($type)->where('restaurant_id', $request['vendor']->restaurants[0]->id)->latest()->paginate($limit, ['*'], 'page', $offset);
        $data = [
            'total_size' => $paginator->total(),
            'limit' => $limit,
            'offset' => $offset,
            'products' => Helpers::product_data_formatting($paginator->items(), true, true, app()->getLocale())
        ];

        return response()->json($data, 200);
    }

    public function update_bank_info(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'bank_name' => 'required|max:191',
            'branch' => 'required|max:191',
            'holder_name' => 'required|max:191',
            'account_no' => 'required|max:191'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $bank = $request['vendor'];
        $bank->bank_name = $request->bank_name;
        $bank->branch = $request->branch;
        $bank->holder_name = $request->holder_name;
        $bank->account_no = $request->account_no;
        $bank->save();

        return response()->json(['message' => translate('messages.bank_info_updated_successfully'), 200]);
    }

    public function withdraw_list(Request $request)
    {
        // $limit=$request->limit?$request->limit:25;
        // $offset=$request->offset?$request->offset:1;
        $withdraw_req = WithdrawRequest::where('vendor_id', $request['vendor']->id)->latest()->get();
        // ->paginate($limit, ['*'], 'page', $offset);
        $temp = [];
        $status = [
            0 => 'Pending',
            1 => 'Approved',
            2 => 'Denied'
        ];
        foreach ($withdraw_req as $item) {
            $item['status'] = $status[$item->approved];
            $item['requested_at'] = $item->created_at->format('Y-m-d H:i:s');
            $item['bank_name'] = $request['vendor']->bank_name;
            unset($item['created_at']);
            unset($item['approved']);
            $temp[] = $item;
        }
        // $data = [
        //     'total_size' => $withdraw_req->total(),
        //     'limit' => $limit,
        //     'offset' => $offset,
        //     'withdraw_requests' => $temp
        // ];
        return response()->json($temp, 200);
    }

    public function request_withdraw(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01'
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $w = $request['vendor']->wallet;
        if ($w->balance >= $request['amount']) {
            $data = [
                'vendor_id' => $w->vendor_id,
                'amount' => $request['amount'],
                'transaction_note' => null,
                'approved' => 0,
                'created_at' => now(),
                'updated_at' => now()
            ];
            try {
                DB::table('withdraw_requests')->insert($data);
                $w->increment('pending_withdraw', $request['amount']);
                return response()->json(['message' => translate('messages.withdraw_request_placed_successfully')], 200);
            } catch (\Exception $e) {
                return response()->json($e);
            }
        }
        return response()->json([
            'errors' => [
                ['code' => 'amount', 'message' => translate('messages.insufficient_balance')]
            ]
        ], 403);
    }

    public function remove_account(Request $request)
    {
        $vendor = $request['vendor'];

        if (Order::where('restaurant_id', $vendor->restaurants[0]->id)->whereIn('order_status', ['pending', 'accepted', 'confirmed', 'processing', 'handover', 'picked_up'])->count()) {
            return response()->json(['errors' => [['code' => 'on-going', 'message' => translate('messages.user_account_delete_warning')]]], 203);
        }

        if ($vendor->wallet && $vendor->wallet->collected_cash > 0) {
            return response()->json(['errors' => [['code' => 'on-going', 'message' => translate('messages.user_account_wallet_delete_warning')]]], 203);
        }

        if (Storage::disk('public')->exists('vendor/' . $vendor['image'])) {
            Storage::disk('public')->delete('vendor/' . $vendor['image']);
        }
        if (Storage::disk('public')->exists('restaurant/' . $vendor->restaurants[0]->logo)) {
            Storage::disk('public')->delete('restaurant/' . $vendor->restaurants[0]->logo);
        }

        if (Storage::disk('public')->exists('restaurant/cover/' . $vendor->restaurants[0]->cover_photo)) {
            Storage::disk('public')->delete('restaurant/cover/' . $vendor->restaurants[0]->cover_photo);
        }

        $vendor->restaurants()->delete();
        if ($vendor->userinfo) {
            $vendor->userinfo->delete();
        }
        $vendor->delete();
        return response()->json([]);
    }

    public function getType(Request $request)
    {
        $vendor = $request['vendor'];

        return response()->json([
            'status' => 'valid',
            'message' => 'Type fetched successfully',
            'data' => [
                'type' => $vendor->restaurants[0]->restaurant_type,
                'restaurant_id' => $vendor->restaurants[0]->id,
                'restaurant_name' => $vendor->restaurants[0]->name,
                'restaurant_logo' => $vendor->restaurants[0]->logo ? asset('storage/restaurant/' . $vendor->restaurants[0]->logo) : null
            ]
        ], 200);
    }
}
