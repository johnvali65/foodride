<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Food;
use App\Models\OrderDetail;
use App\Models\User;
use App\Models\UserInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\Zone;
use App\Models\Restaurant;
use Grimzy\LaravelMysqlSpatial\Types\Point;

class CustomerController extends Controller
{
    public function address_list(Request $request)
    {
        $limit = $request['limit'] ?? 10;
        $offset = $request['offset'] ?? 1;

        $addresses = CustomerAddress::where('user_id', $request->user()->id)->latest()->paginate($limit, ['*'], 'page', $offset);

        $data = [
            'total_size' => $addresses->total(),
            'limit' => $limit,
            'offset' => $offset,
            'addresses' => Helpers::address_data_formatting($addresses->items())
        ];
        return response()->json($data, 200);
    }

    public function add_new_address(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'contact_person_name' => 'required',
            'address_type' => 'required',
            'contact_person_number' => 'required',
            'address' => 'required',
            'longitude' => 'required',
            'latitude' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $point = new Point($request->latitude, $request->longitude);
        $zone = Zone::contains('coordinates', $point)->get(['id']);
        if (count($zone) == 0) {
            $errors = [];
            array_push($errors, ['code' => 'coordinates', 'message' => translate('messages.service_not_available_in_this_area')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $address = [
            'user_id' => $request->user()->id,
            'contact_person_name' => $request->contact_person_name,
            'contact_person_number' => $request->contact_person_number,
            'address_type' => $request->address_type,
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'longitude' => $request->longitude,
            'latitude' => $request->latitude,
            'zone_id' => $zone[0]->id,
            'created_at' => now(),
            'updated_at' => now()
        ];
        DB::table('customer_addresses')->insert($address);
        return response()->json(['message' => translate('messages.successfully_added'), 'zone_ids' => array_column($zone->toArray(), 'id')], 200);
    }

    public function awt_delete_address(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if (DB::table('customer_addresses')->where(['id' => $request['address_id'], 'user_id' => $request->user()->id])->first()) {
            DB::table('customer_addresses')->where(['id' => $request['address_id'], 'user_id' => $request->user()->id])->delete();
            return response()->json(['message' => translate('messages.successfully_removed')], 200);
        }
        return response()->json(['message' => translate('messages.not_found')], 404);
    }

    public function awt_update_address(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'contact_person_name' => 'required',
            'address_type' => 'required',
            'contact_person_number' => 'required',
            'address' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $address = [
            'user_id' => $request->awt_userid,
            'contact_person_name' => $request->contact_person_name,
            'contact_person_number' => $request->contact_person_number,
            'address_type' => $request->address_type,
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'created_at' => now(),
            'updated_at' => now()
        ];

        $awt_Q = DB::table('customer_addresses')->where('id', $request->awt_addressid)->update($address);
        return response()->json(['message' => translate('messages.updated_successfully'), 'zone_id' => $request->awt_zone_id], 200);
    }

    public function update_address(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'contact_person_name' => 'required',
            'address_type' => 'required',
            'contact_person_number' => 'required',
            'address' => 'required',
            'longitude' => 'required',
            'latitude' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $point = new Point($request->latitude, $request->longitude);
        $zone = Zone::contains('coordinates', $point)->first();
        if (!$zone) {
            $errors = [];
            array_push($errors, ['code' => 'coordinates', 'message' => translate('messages.service_not_available_in_this_area')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }
        $address = [
            'user_id' => $request->user()->id,
            'contact_person_name' => $request->contact_person_name,
            'contact_person_number' => $request->contact_person_number,
            'address_type' => $request->address_type,
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'longitude' => $request->longitude,
            'latitude' => $request->latitude,
            'zone_id' => $zone->id,
            'created_at' => now(),
            'updated_at' => now()
        ];
        DB::table('customer_addresses')->where('id', $id)->update($address);
        return response()->json(['message' => translate('messages.updated_successfully'), 'zone_id' => $zone->id], 200);
    }

    public function delete_address(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'address_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if (DB::table('customer_addresses')->where(['id' => $request['address_id'], 'user_id' => $request->user()->id])->first()) {
            DB::table('customer_addresses')->where(['id' => $request['address_id'], 'user_id' => $request->user()->id])->delete();
            return response()->json(['message' => translate('messages.successfully_removed')], 200);
        }
        return response()->json(['message' => translate('messages.not_found')], 404);
    }

    public function get_order_list(Request $request)
    {
        $orders = Order::with('restaurant')->where(['user_id' => $request->user()->id])->get();
        return response()->json($orders, 200);
    }

    public function get_order_details(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $details = OrderDetail::where(['order_id' => $request['order_id']])->get();
        foreach ($details as $det) {
            $det['product_details'] = json_decode($det['product_details'], true);
        }

        return response()->json($details, 200);
    }

    public function info(Request $request)
    {
        $data = $request->user();
        $data['userinfo'] = $data->userinfo;
        $data['order_count'] = (integer) $request->user()->orders->count();
        $data['member_since_days'] = (integer) $request->user()->created_at->diffInDays();
        unset($data['orders']);
        return response()->json($data, 200);
    }

    public function profile_details(Request $request)
    {
        $user = $request->user();
        $data['f_name'] = $user->f_name;
        $data['l_name'] = $user->l_name;
        $data['email'] = $user->email;
        $data['phone'] = $user->phone;
        return response()->json($data, 200);
    }

    public function update_profile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'f_name' => 'required',
            'l_name' => 'required',
            'email' => 'required|unique:users,email,' . $request->user()->id,
        ], [
            'f_name.required' => 'First name is required!',
            'l_name.required' => 'Last name is required!',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $image = $request->file('image');

        if ($request->has('image')) {
            $imageName = Helpers::update('profile/', $request->user()->image, 'png', $request->file('image'));
        } else {
            $imageName = $request->user()->image;
        }

        if ($request['password'] != null && strlen($request['password']) > 5) {
            $pass = bcrypt($request['password']);
        } else {
            $pass = $request->user()->password;
        }

        $userDetails = [
            'f_name' => $request->f_name,
            'l_name' => $request->l_name,
            'email' => $request->email,
            'image' => $imageName,
            'password' => $pass,
            'updated_at' => now()
        ];

        User::where(['id' => $request->user()->id])->update($userDetails);
        if ($request->user()->userinfo) {
            UserInfo::where(['user_id' => $request->user()->id])->update([
                'f_name' => $request->f_name,
                'l_name' => $request->l_name,
                'email' => $request->email,
                'image' => $imageName
            ]);
        }


        return response()->json(['message' => translate('messages.successfully_updated')], 200);
    }
    public function update_interest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'interest' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $userDetails = [
            'interest' => json_encode($request->interest),
        ];

        User::where(['id' => $request->user()->id])->update($userDetails);

        return response()->json(['message' => translate('messages.interest_updated_successfully')], 200);
    }

    public function update_cm_firebase_token(Request $request)
    {
        info('Updating Firebase token for user ID: ' . json_encode($request->all()));
        $validator = Validator::make($request->all(), [
            'cm_firebase_token' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if ($request->device_type != '' && $request->device_type == 'ios') {
            DB::table('users')->where('id', $request->user()->id)->update([
                'cm_firebase_token_ios' => $request['cm_firebase_token']
            ]);
        } else {
            DB::table('users')->where('id', $request->user()->id)->update([
                'cm_firebase_token' => $request['cm_firebase_token']
            ]);
        }

        return response()->json(['message' => translate('messages.updated_successfully')], 200);
    }

    public function get_suggested_food(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => 'Zone id is required!']);
            return response()->json([
                'errors' => $errors
            ], 403);
        }


        $zone_id = json_decode($request->header('zoneId'), true);

        $interest = $request->user()->interest;
        $interest = isset($interest) ? json_decode($interest) : null;
        // return response()->json($interest, 200);

        $products = Food::active()->whereHas('restaurant', function ($q) use ($zone_id) {
            $q->whereIn('zone_id', $zone_id);
        })
            ->when(isset($interest), function ($q) use ($interest) {
                return $q->whereIn('category_id', $interest);
            })
            ->when($interest == null, function ($q) {
                return $q->popular();
            })->limit(5)->get();
        $products = Helpers::product_data_formatting($products, true, false, app()->getLocale());
        return response()->json($products, 200);
    }

    public function update_zone(Request $request)
    {
        if (!$request->hasHeader('zoneId') && is_numeric($request->header('zoneId'))) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $customer = $request->user();
        $customer->zone_id = (integer) json_decode($request->header('zoneId'), true)[0];
        $customer->save();
        return response()->json([], 200);
    }

    public function remove_account(Request $request)
    {
        $user = $request->user();

        if (Order::where('user_id', $user->id)->whereIn('order_status', ['pending', 'accepted', 'confirmed', 'processing', 'handover', 'picked_up'])->count()) {
            return response()->json(['errors' => [['code' => 'on-going', 'message' => translate('messages.user_account_delete_warning')]]], 203);
        }
        $request->user()->token()->revoke();
        if ($user->userinfo) {
            $user->userinfo->delete();
        }
        $user->delete();
        return response()->json([]);
    }

    public function awt_address_list(Request $request)
    {
        //  $limit = $request['limit']??10;
        //    $offset = $request['offset']??1;

        $limit = 10;
        $offset = 1;
        $addresses = CustomerAddress::where('user_id', $request->awt_user_id)->latest()->paginate($limit, ['*'], 'page', $offset);

        $data = [
            'total_size' => $addresses->total(),
            'limit' => $limit,
            'offset' => $offset,
            'addresses' => Helpers::address_data_formatting($addresses->items())
        ];
        return response()->json($data, 200);
    }

    public function awt_add_new_address(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'contact_person_name' => 'required',
            'address_type' => 'required',
            'contact_person_number' => 'required',
            'address' => 'required',
            'longitude' => 'required',
            'latitude' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $point = new Point($request->latitude, $request->longitude);

        $zone = Zone::contains('coordinates', $point)->get(['id']);
        //dd($zone);exit;
        if (count($zone) == 0) {
            $errors = [];
            array_push($errors, ['code' => 'coordinates', 'message' => trans('messages.service_not_available_in_this_area')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $address = [
            'user_id' => $request->user_id,
            'contact_person_name' => $request->contact_person_name,
            'contact_person_number' => $request->contact_person_number,
            'address_type' => $request->address_type,
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'longitude' => $request->longitude,
            'latitude' => $request->latitude,
            'zone_id' => $zone[0]->id,
            // 'zone_id' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ];
        DB::table('customer_addresses')->insert($address);
        return response()->json(['message' => trans('messages.successfully_added'), 'zone_ids' => array_column($zone->toArray(), 'id')], 200);
    }

    public function address_check(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'longitude' => 'required',
            'latitude' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $point = new Point($request->latitude, $request->longitude);

        if ($request->restaurant_id) {
            $restaurant = Restaurant::where('id', $request->restaurant_id)->first();
            $zone = Zone::where('id', $restaurant->zone_id)->contains('coordinates', $point)->get(['id']);
        } else {
            $zone = Zone::contains('coordinates', $point)->get(['id']);
        }

        //dd($zone);exit;
        if (count($zone) == 0) {
            return response()->json(['status' => 'invalid', 'message' => trans('messages.service_not_available_in_this_area')], 403);
        } else {
            return response()->json(['status' => 'valid', 'message' => trans('messages.service_available_in_this_area')], 200);
        }
    }

    public function awt_update_profile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'f_name' => 'required'
        ], [
            'f_name.required' => 'Full name is required!'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if ($request->image != 'null') {
            if ($request->has('image')) {
                $image = $request->file('image');
                // Get filename with the extension
                $filenameWithExt = $request->file('image')->getClientOriginalName();
                $imageName = Helpers::update('profile/', $request->user()->image, $filenameWithExt, $request->file('image'));
            } else {
                $imageName = $request->user()->image;
            }
        } else {
            $imageName = null;
        }

        $userDetails = [
            'f_name' => $request->f_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'image' => $imageName,
            'updated_at' => now()
        ];

        User::where(['id' => $request->user()->id])->update($userDetails);
        if ($request->user()->userinfo) {
            UserInfo::where(['user_id' => $request->user()->id])->update([
                'f_name' => $request->f_name,
                'email' => $request->email,
                'phone' => $request->phone
            ]);
        }
        $user = User::find($request->user()->id);


        return response()->json(['message' => trans('messages.successfully_updated'), 'data' => $user], 200);
    }

    public function remove_account_ios(Request $request)
    {
        $user = $request->user();

        if (Order::where('user_id', $user->id)->whereIn('order_status', ['pending', 'accepted', 'confirmed', 'processing', 'handover', 'picked_up'])->count()) {
            return response()->json(['status' => 'invalid', 'message' => translate('messages.user_account_delete_warning')]);
        }
        $request->user()->token()->revoke();
        if ($user->userinfo) {
            $user->userinfo->delete();
        }
        $user->delete();
        return response()->json(['status' => 'valid', 'message' => 'Deleted Successfully']);
    }
}
