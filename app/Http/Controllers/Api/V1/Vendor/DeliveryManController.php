<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\DeliveryMan;
use App\Models\DMReview;
use App\Models\Order;
use App\Models\Zone;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DeliveryManController extends Controller
{
    public function __construct(Request $request)
    {
        $this->middleware(function ($request, $next) {
            // if(!$request->vendor->restaurants[0]->self_delivery_system)
            // {
            //     return response()->json([
            //         'errors'=>[
            //             ['code'=>'unauthorized', 'message'=>translate('messages.permission_denied')]
            //         ]
            //     ],403);
            // }
            return $next($request);
        });

    }
    public function list(Request $request)
    {
        $restaurantId = $request->vendor->restaurants[0]->id;

        $delivery_men = DeliveryMan::with(['rating', 'wallet'])
            ->withCount([
                'orders' => function ($query) {
                    $query->where('order_status', 'delivered');
                }
            ])
            ->where('restaurant_id', $restaurantId)
            ->latest()
            ->get()
            ->map(function ($data) use ($request) {
                // Decode identity images
                $data->identity_image = json_decode($data->identity_image);

                // Cast numeric fields
                $data->orders_count = (double) $data->orders_count;
                $data['avg_rating'] = (double) (!empty($data->rating[0]) ? $data->rating[0]->average : 0);
                $data['rating_count'] = (double) (!empty($data->rating[0]) ? $data->rating[0]->rating_count : 0);
                $data['cash_in_hands'] = $data->wallet ? $data->wallet->collected_cash : 0;
                $data['location'] = $request->vendor->restaurants[0]->zone->name ?? null;

                // Determine active_status
                $onRideOrderExists = \App\Models\Order::where('delivery_man_id', $data->id)
                    ->whereIn('order_status', ['pending', 'accepted', 'processing', 'picked_up'])
                    ->exists();

                if ($onRideOrderExists) {
                    $data['active_status'] = 2; // On Ride
                } else {
                    $data['active_status'] = $data->active == 1 ? 1 : 0; // Online or Offline
                }

                // Clean up
                unset($data['rating']);
                unset($data['wallet']);

                return $data;
            });

        return response()->json($delivery_men, 200);
    }

    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $key = explode(' ', $request['search']);
        $delivery_men = DeliveryMan::where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('f_name', 'like', "%{$value}%")
                    ->orWhere('l_name', 'like', "%{$value}%")
                    ->orWhere('email', 'like', "%{$value}%")
                    ->orWhere('phone', 'like', "%{$value}%")
                    ->orWhere('identity_number', 'like', "%{$value}%");
            }
        })->where('restaurant_id', $request->vendor->restaurants[0]->id)->limit(50)->get();
        return response()->json($delivery_men);
    }

    public function preview(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'delivery_man_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $dm = DeliveryMan::with(['reviews.customer', 'rating'])
            ->withCount([
                'orders' => function ($query) {
                    $query->where('order_status', 'delivered');
                }
            ])
            ->where('restaurant_id', $request->vendor->restaurants[0]->id)->where(['id' => $request->delivery_man_id])->first();
        $dm['avg_rating'] = (double) (!empty($dm->rating[0]) ? $dm->rating[0]->average : 0);
        $dm['rating_count'] = (double) (!empty($dm->rating[0]) ? $dm->rating[0]->rating_count : 0);
        $dm['cash_in_hands'] = $dm->wallet ? $dm->wallet->collected_cash : 0;
        unset($dm['rating']);
        unset($dm['wallet']);
        return response()->json($dm, 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'f_name' => 'required',
            'identity_type' => 'required|in:passport,driving_license,nid,restaurant_id',
            'identity_number' => 'required',
            'email' => 'required|unique:delivery_men',
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:10|unique:delivery_men',
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if ($request->has('image')) {
            $image_name = Helpers::upload('delivery-man/', 'png', $request->file('image'));
        } else {
            $image_name = 'def.png';
        }

        $id_img_names = [];
        if (!empty($request->file('identity_image'))) {
            foreach ($request->identity_image as $img) {
                $identity_image = Helpers::upload('delivery-man/', 'png', $img);
                array_push($id_img_names, $identity_image);
            }
            $identity_image = json_encode($id_img_names);
        } else {
            $identity_image = json_encode([]);
        }

        $dm = new DeliveryMan();
        $dm->f_name = $request->f_name;
        $dm->l_name = $request->l_name;
        $dm->email = $request->email;
        $dm->phone = '+91' . $request->phone;
        $dm->identity_number = $request->identity_number;
        $dm->identity_type = $request->identity_type;
        $dm->restaurant_id = $request->vendor->restaurants[0]->id;
        $dm->identity_image = $identity_image;
        $dm->image = $image_name;
        $dm->active = 0;
        $dm->earning = 0;
        $dm->type = 'restaurant_wise';
        $dm->password = bcrypt($request->password);
        $dm->save();

        return response()->json(['message' => translate('messages.deliveryman_added_successfully')], 200);
    }


    public function status(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'delivery_man_id' => 'required',
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $delivery_man = DeliveryMan::find($request->delivery_man_id);
        if (!$delivery_man) {
            return response()->json([
                'errors' => [
                    ['code' => 'delivery_man_id', 'message' => translate('messages.not_found')]
                ]
            ], 404);
        }
        $delivery_man->status = $request->status;

        try {
            if ($request->status == 0) {
                $delivery_man->auth_token = null;
                if (isset($delivery_man->fcm_token)) {
                    $data = [
                        'title' => translate('messages.suspended'),
                        'description' => translate('messages.your_account_has_been_suspended'),
                        'order_id' => '',
                        'image' => '',
                        'type' => 'block'
                    ];
                    Helpers::send_push_notif_to_device($delivery_man->fcm_token, $data);

                    DB::table('user_notifications')->insert([
                        'data' => json_encode($data),
                        'delivery_man_id' => $delivery_man->id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }

            }

        } catch (\Exception $e) {

        }

        $delivery_man->save();

        return response()->json(['message' => translate('messages.deliveryman_status_updated')], 200);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'f_name' => 'required',
            'email' => 'required|unique:delivery_men,email,' . $id,
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/|min:10|unique:delivery_men,phone,' . $id,
            'password' => 'nullable|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $delivery_man = DeliveryMan::find($id);
        if (!$delivery_man) {
            return response()->json([
                'errors' => [
                    ['code' => 'delivery_man_id', 'message' => translate('messages.not_found')]
                ]
            ], 404);
        }
        if ($request->has('image')) {
            $image_name = Helpers::update('delivery-man/', $delivery_man->image, 'png', $request->file('image'));
        } else {
            $image_name = $delivery_man['image'];
        }

        if ($request->has('identity_image')) {
            foreach (json_decode($delivery_man['identity_image'], true) as $img) {
                if (Storage::disk('public')->exists('delivery-man/' . $img)) {
                    Storage::disk('public')->delete('delivery-man/' . $img);
                }
            }
            $img_keeper = [];
            foreach ($request->identity_image as $img) {
                $identity_image = Helpers::upload('delivery-man/', 'png', $img);
                array_push($img_keeper, $identity_image);
            }
            $identity_image = json_encode($img_keeper);
        } else {
            $identity_image = $delivery_man['identity_image'];
        }

        $delivery_man->f_name = $request->f_name;
        $delivery_man->l_name = $request->l_name;
        $delivery_man->email = $request->email;
        $delivery_man->phone = '+91' . $request->phone;
        $delivery_man->identity_number = $request->identity_number;
        $delivery_man->identity_type = $request->identity_type;
        $delivery_man->identity_image = $identity_image;
        $delivery_man->image = $image_name;

        $delivery_man->password = strlen($request->password) > 1 ? bcrypt($request->password) : $delivery_man['password'];
        $delivery_man->save();

        return response()->json(['message' => translate('messages.deliveryman_updated_successfully')], 200);
    }

    public function delete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'delivery_man_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $delivery_man = DeliveryMan::find($request->delivery_man_id);
        if (!$delivery_man) {
            return response()->json([
                'errors' => [
                    ['code' => 'delivery_man_id', 'message' => translate('messages.not_found')]
                ]
            ], 404);
        }
        if (Storage::disk('public')->exists('delivery-man/' . $delivery_man['image'])) {
            Storage::disk('public')->delete('delivery-man/' . $delivery_man['image']);
        }

        foreach (json_decode($delivery_man['identity_image'], true) as $img) {
            if (Storage::disk('public')->exists('delivery-man/' . $img)) {
                Storage::disk('public')->delete('delivery-man/' . $img);
            }
        }

        $delivery_man->delete();

        return response()->json(['message' => translate('messages.deliveryman_deleted_successfully')], 200);
    }

    public function assignDeliveryMan(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
            'delivery_man_id' => 'required|exists:delivery_men,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $order = Order::notpos()->find($request->order_id);
        $deliveryman = DeliveryMan::where('id', $request->delivery_man_id)
            ->available()
            ->active()
            ->first();

        if (!$deliveryman) {
            return response()->json(['message' => 'Deliveryman not available'], 404);
        }

        if ($order->delivery_man_id == $deliveryman->id) {
            return response()->json(['message' => 'Order already assigned to this deliveryman'], 400);
        }

        if ($deliveryman->current_orders >= config('dm_maximum_orders', 10)) {
            return response()->json(['message' => 'Delivery man has reached max allowed orders'], 400);
        }

        // Check cash-in-hand logic
        $cashInHand = $deliveryman->wallet ? $deliveryman->wallet->collected_cash : 0;
        $maxCashSetting = BusinessSetting::where('key', 'dm_max_cash_in_hand')->first();
        $maxCash = $maxCashSetting ? $maxCashSetting->value : 0;

        if ($order->payment_method === 'cash_on_delivery' && ($cashInHand + $order->order_amount) >= $maxCash) {
            return response()->json(['message' => 'Delivery man max cash in hand exceeded'], 400);
        }

        // Unassign current delivery man (if any)
        if ($order->delivery_man_id && $order->delivery_man_id != $deliveryman->id) {
            $oldDM = DeliveryMan::find($order->delivery_man_id);
            if ($oldDM) {
                $oldDM->current_orders = max(0, $oldDM->current_orders - 1);
                $oldDM->save();

                $data = [
                    'title' => 'Order Update',
                    'description' => 'You are unassigned from an order.',
                    'order_id' => '',
                    'image' => '',
                    'type' => 'assign'
                ];
                Helpers::send_push_notif_to_device($oldDM->fcm_token, $data);

                DB::table('user_notifications')->insert([
                    'data' => json_encode($data),
                    'delivery_man_id' => $oldDM->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
        }

        // Assign new delivery man
        $order->delivery_man_id = $deliveryman->id;
        if (in_array($order->order_status, ['pending', 'confirmed'])) {
            $order->order_status = 'accepted';
        }
        $order->accepted = now();
        $order->save();

        $deliveryman->increment('current_orders');
        $deliveryman->increment('assigned_order_count');

        // Push notification to customer
        if ($order->customer) {
            $value = Helpers::order_status_update_message('accepted');
            $data = [
                'title' => 'Order Update',
                'description' => $value,
                'order_id' => $order->id,
                'image' => '',
                'type' => 'order_status'
            ];
            Helpers::send_push_notif_to_device($order->customer->cm_firebase_token, $data);

            DB::table('user_notifications')->insert([
                'data' => json_encode($data),
                'user_id' => $order->customer->id,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        // Push notification to delivery man
        $data = [
            'title' => 'Order Assignment',
            'description' => 'You are assigned to a new order.',
            'order_id' => $order->id,
            'image' => '',
            'type' => 'assign'
        ];
        Helpers::send_push_notif_to_device($deliveryman->fcm_token, $data);

        DB::table('user_notifications')->insert([
            'data' => json_encode($data),
            'delivery_man_id' => $deliveryman->id,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return response()->json(['message' => 'Delivery man assigned successfully'], 200);
    }
}
