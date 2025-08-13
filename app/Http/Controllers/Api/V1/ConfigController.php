<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\Currency;
use App\Models\Restaurant;
use App\Models\Zone;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ConfigController extends Controller
{
    private $map_api_key;

    function __construct()
    {
        $map_api_key_server = BusinessSetting::where(['key' => 'map_api_key_server'])->first();
        $map_api_key_server = $map_api_key_server ? $map_api_key_server->value : null;
        $this->map_api_key = $map_api_key_server;
    }

    public function configuration()
    {

        $key = [
            'cash_on_delivery',
            'digital_payment',
            'default_location',
            'free_delivery_over',
            'business_name',
            'logo',
            'address',
            'phone',
            'email_address',
            'country',
            'currency_symbol_position',
            'app_minimum_version_android',
            'app_url_android',
            'app_minimum_version_ios',
            'app_url_ios',
            'customer_verification',
            'order_delivery_verification',
            'terms_and_conditions',
            'privacy_policy',
            'about_us',
            'per_km_shipping_charge',
            'minimum_shipping_charge',
            'maintenance_mode',
            'popular_food',
            'popular_restaurant',
            'new_restaurant',
            'most_reviewed_foods',
            'show_dm_earning',
            'canceled_by_deliveryman',
            'canceled_by_restaurant',
            'timeformat',
            'toggle_veg_non_veg',
            'toggle_dm_registration',
            'toggle_restaurant_registration',
            'schedule_order_slot_duration',
            'loyalty_point_exchange_rate',
            'loyalty_point_item_purchase_point',
            'loyalty_point_status',
            'loyalty_point_minimum_point',
            'wallet_status',
            'schedule_order',
            'dm_tips_status',
            'ref_earning_status',
            'ref_earning_exchange_rate',
            'theme'
        ];

        $settings = array_column(BusinessSetting::whereIn('key', $key)->get()->toArray(), 'value', 'key');
        $currency_symbol = Currency::where(['currency_code' => Helpers::currency_code()])->first()->currency_symbol;
        $cod = json_decode($settings['cash_on_delivery'], true);
        $cod = json_decode($settings['cash_on_delivery'], true);
        $digital_payment = json_decode($settings['digital_payment'], true);

        $default_location = isset($settings['default_location']) ? json_decode($settings['default_location'], true) : 0;
        $free_delivery_over = $settings['free_delivery_over'];
        $free_delivery_over = $free_delivery_over ? (float) $free_delivery_over : $free_delivery_over;
        $languages = Helpers::get_business_settings('language');
        $lang_array = [];
        foreach ($languages as $language) {
            array_push($lang_array, [
                'key' => $language,
                'value' => Helpers::get_language_name($language)
            ]);
        }

        //dd($settings['ref_earning_exchange_rate']);
        return response()->json([
            'business_name' => $settings['business_name'],
            'logo' => $settings['logo'],
            'address' => $settings['address'],
            'phone' => $settings['phone'],
            'email' => $settings['email_address'],
            'base_urls' => [
                'product_image_url' => asset('storage/app/public/product'),
                'customer_image_url' => asset('storage/app/public/profile'),
                'banner_image_url' => asset('storage/app/public/banner'),
                'category_image_url' => asset('storage/app/public/category'),
                'review_image_url' => asset('storage/app/public/review'),
                'notification_image_url' => asset('storage/app/public/notification'),
                'restaurant_image_url' => asset('storage/app/public/restaurant'),
                'vendor_image_url' => asset('storage/app/public/vendor'),
                'restaurant_cover_photo_url' => asset('storage/app/public/restaurant/cover'),
                'delivery_man_image_url' => asset('storage/app/public/delivery-man'),
                'chat_image_url' => asset('storage/app/public/conversation'),
                'campaign_image_url' => asset('storage/app/public/campaign'),
                'business_logo_url' => asset('storage/app/public/business')
            ],
            'country' => $settings['country'],
            'default_location' => ['lat' => $default_location ? $default_location['lat'] : '23.757989', 'lng' => $default_location ? $default_location['lng'] : '90.360587'],
            'currency_symbol' => $currency_symbol,
            'currency_symbol_direction' => $settings['currency_symbol_position'],
            'app_minimum_version_android' => (int) $settings['app_minimum_version_android'],
            'app_url_android' => $settings['app_url_android'],
            'app_minimum_version_ios' => (int) $settings['app_minimum_version_ios'],
            'app_url_ios' => $settings['app_url_ios'],
            'customer_verification' => (bool) $settings['customer_verification'],
            'schedule_order' => (bool) $settings['schedule_order'],
            'order_delivery_verification' => (bool) $settings['order_delivery_verification'],
            'cash_on_delivery' => (bool) ($cod['status'] == 1 ? true : false),
            'digital_payment' => (bool) ($digital_payment['status'] == 1 ? true : false),
            'terms_and_conditions' => $settings['terms_and_conditions'],
            'privacy_policy' => $settings['privacy_policy'],
            'about_us' => $settings['about_us'],
            'per_km_shipping_charge' => (float) $settings['per_km_shipping_charge'],
            'minimum_shipping_charge' => (float) $settings['minimum_shipping_charge'],
            'free_delivery_over' => $free_delivery_over,
            'demo' => (bool) (env('APP_MODE') == 'demo' ? true : false),
            'maintenance_mode' => (bool) Helpers::get_business_settings('maintenance_mode') ?? 0,
            'order_confirmation_model' => config('order_confirmation_model'),
            'popular_food' => (float) $settings['popular_food'],
            'popular_restaurant' => (float) $settings['popular_restaurant'],
            'new_restaurant' => (float) $settings['new_restaurant'],
            'most_reviewed_foods' => (float) $settings['most_reviewed_foods'],
            'show_dm_earning' => (bool) $settings['show_dm_earning'],
            'canceled_by_deliveryman' => (bool) $settings['canceled_by_deliveryman'],
            'canceled_by_restaurant' => (bool) $settings['canceled_by_restaurant'],
            'timeformat' => (string) $settings['timeformat'],
            'language' => $lang_array,
            'toggle_veg_non_veg' => (bool) $settings['toggle_veg_non_veg'],
            'toggle_dm_registration' => (bool) $settings['toggle_dm_registration'],
            'toggle_restaurant_registration' => (bool) $settings['toggle_restaurant_registration'],
            'schedule_order_slot_duration' => (int) $settings['schedule_order_slot_duration'],
            'digit_after_decimal_point' => (int) config('round_up_to_digit'),
            'loyalty_point_exchange_rate' => (int) (isset($settings['loyalty_point_item_purchase_point']) ? $settings['loyalty_point_exchange_rate'] : 0),
            'loyalty_point_item_purchase_point' => (float) (isset($settings['loyalty_point_item_purchase_point']) ? $settings['loyalty_point_item_purchase_point'] : 0.0),
            'loyalty_point_status' => (int) (isset($settings['loyalty_point_status']) ? $settings['loyalty_point_status'] : 0),
            'minimum_point_to_transfer' => (int) (isset($settings['loyalty_point_minimum_point']) ? $settings['loyalty_point_minimum_point'] : 0),
            'customer_wallet_status' => (int) (isset($settings['wallet_status']) ? $settings['wallet_status'] : 0),
            'ref_earning_status' => (int) (isset($settings['ref_earning_status']) ? $settings['ref_earning_status'] : 0),
            'ref_earning_exchange_rate' => (double) (isset($settings['ref_earning_exchange_rate']) ? $settings['ref_earning_exchange_rate'] : 0),
            'dm_tips_status' => (int) (isset($settings['dm_tips_status']) ? $settings['dm_tips_status'] : 0),
            'theme' => (int) $settings['theme'],
        ]);
    }

    public function get_zone(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lat' => 'required',
            'lng' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $point = new Point($request->lat, $request->lng);
        $zones = Zone::contains('coordinates', $point)->latest()->get(['id', 'status', 'minimum_shipping_charge', 'per_km_shipping_charge']);
        if (count($zones) < 1) {
            return response()->json([
                'errors' => [
                    ['code' => 'coordinates', 'message' => translate('messages.service_not_available_in_this_area')]
                ]
            ], 404);
        }
        $data = array_filter($zones->toArray(), function ($zone) {
            if ($zone['status'] == 1) {
                return $zone;
            }
        });

        if (count($data) > 0) {
            return response()->json(['zone_id' => json_encode(array_column($data, 'id')), 'zone_data' => array_values($data)], 200);
        }

        return response()->json([
            'errors' => [
                ['code' => 'coordinates', 'message' => translate('messages.we_are_temporarily_unavailable_in_this_area')]
            ]
        ], 403);
    }

    public function place_api_autocomplete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'search_text' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $response = Http::get('https://maps.googleapis.com/maps/api/place/autocomplete/json?input=' . $request['search_text'] . '&key=' . $this->map_api_key);
        return $response->json();
    }


    public function distance_api(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'origin_lat' => 'required',
            'origin_lng' => 'required',
            'destination_lat' => 'required',
            'destination_lng' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $response = Http::get('https://maps.googleapis.com/maps/api/distancematrix/json?origins=' . $request['origin_lat'] . ',' . $request['origin_lng'] . '&destinations=' . $request['destination_lat'] . ',' . $request['destination_lng'] . '&key=' . $this->map_api_key . '&mode=walking');
        return $response->json();
    }


    public function place_api_details(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'placeid' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $response = Http::get('https://maps.googleapis.com/maps/api/place/details/json?placeid=' . $request['placeid'] . '&key=' . $this->map_api_key);
        return $response->json();
    }

    public function geocode_api(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lat' => 'required',
            'lng' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json?latlng=' . $request->lat . ',' . $request->lng . '&key=' . $this->map_api_key);
        return $response->json();
    }

    public function awt_get_zone(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lat' => 'required',
            'lng' => 'required',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $point = new Point($request->lat, $request->lng);
        $zones = Zone::contains('coordinates', $point)->latest()->get(['id', 'status', 'minimum_shipping_charge', 'per_km_shipping_charge']);
        if (count($zones) < 1) {
            return response()->json([
                'errors' => [
                    ['code' => 'coordinates', 'message' => trans('messages.service_not_available_in_this_area')]
                ]
            ], 404);
        }
        $data = array_filter($zones->toArray(), function ($zone) {
            if ($zone['status'] == 1) {
                return $zone;
            }
        });

        if (count($data) > 0) {
            return response()->json(['zone_id' => json_encode(array_column($data, 'id')), 'zone_data' => array_values($data)], 200);
        }

        return response()->json([
            'errors' => [
                ['code' => 'coordinates', 'message' => trans('messages.we_are_temporarily_unavailable_in_this_area')]
            ]
        ], 403);
    }

    public function awt_distance_fare_api(Request $request)
    {
        $distanceStr1 = $request->awt_distance_kms;
        $dataAr1 = explode(" ", $distanceStr1);
        $basic_dis_kms = 3;
        $basic_dis_fare = 25;
        $extra_dis_kms_max = 5;
        $extra_dis_fare_per_km = 4;
        $final_max_fare = 60;
        $final_cust_fare = 0;
        if ($dataAr1[0] > $basic_dis_kms) {
            if ($dataAr1[0] <= 5) {
                $extra_cus_kms = $dataAr1[0] - $basic_dis_kms;
                $sub_final_fare = $extra_cus_kms * 4;
                $final_cust_fare = $basic_dis_fare + $sub_final_fare;
            } else {
                $final_cust_fare = 60;
            }
        } else {
            $final_cust_fare = 25;
        }
        $dataArr = array("distance_string" => $dataAr1[0], "distance_amount" => $final_cust_fare);
        //dd($dataArr);exit;
        return $dataArr;
        //return json_encode($dataArr);
    }

    // public function awt_distance_api(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'origin_lat' => 'required',
    //         'origin_lng' => 'required',
    //         'destination_lat' => 'required',
    //         'destination_lng' => 'required',
    //     ]);

    //     if ($validator->errors()->count() > 0) {
    //         return response()->json(['errors' => Helpers::error_processor($validator)], 403);
    //     }

    //     $response = Http::get('https://maps.googleapis.com/maps/api/distancematrix/json?origins=' . $request['origin_lat'] . ',' . $request['origin_lng'] . '&destinations=' . $request['destination_lat'] . ',' . $request['destination_lng'] . '&key=' . $this->map_api_key . '&mode=walking');
    //     $response->json();
    //     $jsonObj1 = json_decode($response, true);

    //     foreach($jsonObj1['rows'] as $data1){
    //     	foreach ($data1['elements'] as $data2){
    //     	$distanceStr1 = $data2['distance']['text'];
    //     		$distanceStr = "1.0";
    //     	$distanceMeters = $data2['distance']['value'];
    //     	}
    //     }

    //     $dataAr1=explode(" ",$distanceStr1);
    //     $basic_dis_kms=3;
    //     $basic_dis_fare=BusinessSetting::where('key', 'minimum_shipping_charge')->value('value');
    //     $extra_dis_kms_max=9;
    //     $extra_dis_fare_per_km = BusinessSetting::where('key','per_km_shipping_charge')->value('value');
    //     $final_max_fare=60;

    //     if($dataAr1[0] > $basic_dis_kms){
    //         if($dataAr1[0] <= 9){
    //             $extra_cus_kms = $dataAr1[0] - $basic_dis_kms;
    //             $sub_final_fare = round($extra_cus_kms * $extra_dis_fare_per_km);
    //             $final_cust_fare = $basic_dis_fare + $sub_final_fare;

    //             if($final_cust_fare > 60){
    //                 $final_cust_fare=60;
    //                 $sub_final_fare = 60 - $basic_dis_fare;
    //             }
    //         }else{
    //             $final_cust_fare = 60;
    //             $sub_final_fare = 60 - $basic_dis_fare;
    //         }
    //     }else{
    //         $final_cust_fare = $basic_dis_fare;
    //         $sub_final_fare = 0;
    //     }

    //     if($final_cust_fare > 60){
    //         $final_cust_fare = 60;
    //         $sub_final_fare = 60 - $basic_dis_fare;
    //     }

    //     $final_cust_fare = round($final_cust_fare);
    //     $awt_final_dis = (double)$dataAr1[0];
    //     $awt_final_dis = round($awt_final_dis);
    //     $str_awt_Fianl_dis = $awt_final_dis;
    //    // $final_cust_fare = 1;
    // /*    if($request['origin_lat'] >= 13 && $request['origin_lat'] < 14 && $request['destination_lat'] >= 13 && $request['destination_lat'] < 14){
    //         $final_cust_fare=0;
    //         $dataArr = array("distance_string"=>$dataAr1[0],"distance_amount"=>$final_cust_fare);
    //     }else if($request['origin_lat'] >= 15 && $request['origin_lat'] < 16 && $request['destination_lat'] >= 15 && $request['destination_lat'] < 16){
    //         $final_cust_fare=0;
    //         $dataArr = array("distance_string"=>$dataAr1[0],"distance_amount"=>$final_cust_fare);
    //     }else{
    //         $dataArr = array("distance_string"=>$dataAr1[0],"distance_amount"=>$final_cust_fare);
    //     }        */
    //     //$doubleApiVal = (double)$dataAr1[0];
    //     //$dataArr = array("distance_string"=>$doubleApiVal,"distance_amount"=>$final_cust_fare);

    //     $dataArr = array("distance_string" => $str_awt_Fianl_dis, "distance_amount" => $final_cust_fare, "extra_fee" => $sub_final_fare, "base_fee" => $basic_dis_fare);
    //     return response()->json($dataArr);
    // }

    public function awt_distance_api(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'origin_lat' => 'required',
            'origin_lng' => 'required',
            'destination_lat' => 'required',
            'destination_lng' => 'required',
            'restaurant_id' => 'nullable|exists:restaurants,id',
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        info('awt_distance_api request ' . json_encode($request->all()));

        $response = Http::get(
            'https://maps.googleapis.com/maps/api/distancematrix/json?origins=' .
            $request['origin_lat'] . ',' . $request['origin_lng'] .
            '&destinations=' . $request['destination_lat'] . ',' . $request['destination_lng'] .
            '&key=' . $this->map_api_key . '&mode=walking'
        );

        $response->json(); // retain old structure
        $jsonObj1 = json_decode($response, true);

        foreach ($jsonObj1['rows'] as $data1) {
            foreach ($data1['elements'] as $data2) {
                $distanceStr1 = $data2['distance']['text']; // e.g. "2.4 km"
                $distanceStr = "1.0";
                $distanceMeters = $data2['distance']['value'];
            }
        }

        $dataAr1 = explode(" ", $distanceStr1); // ["2.4", "km"]
        $basic_dis_kms = 3;
        $extra_dis_kms_max = 9;
        $final_max_fare = 60;

        // 🏪 Fetch restaurant fare if provided
        if ($request->has('restaurant_id')) {
            $restaurant = Restaurant::where('id', $request->restaurant_id)
                ->select('minimum_shipping_charge', 'per_km_shipping_charge')
                ->first();

            $basic_dis_fare = $restaurant->minimum_shipping_charge ?? BusinessSetting::where('key', 'minimum_shipping_charge')->value('value');
            $extra_dis_fare_per_km = $restaurant->per_km_shipping_charge ?? BusinessSetting::where('key', 'per_km_shipping_charge')->value('value');
            info('awt_distance_api restaurant fare', [
                'basic_dis_fare' => $basic_dis_fare,
                'extra_dis_fare_per_km' => $extra_dis_fare_per_km
            ]);
        } else {
            // 🌐 Global defaults
            $basic_dis_fare = BusinessSetting::where('key', 'minimum_shipping_charge')->value('value');
            $extra_dis_fare_per_km = BusinessSetting::where('key', 'per_km_shipping_charge')->value('value');
        }

        // 💰 Fare Calculation (same logic)
        if ($dataAr1[0] > $basic_dis_kms) {
            if ($dataAr1[0] <= $extra_dis_kms_max) {
                $extra_cus_kms = $dataAr1[0] - $basic_dis_kms;
                $sub_final_fare = round($extra_cus_kms * $extra_dis_fare_per_km);
                $final_cust_fare = $basic_dis_fare + $sub_final_fare;

                if ($final_cust_fare > $final_max_fare) {
                    $final_cust_fare = $final_max_fare;
                    $sub_final_fare = $final_max_fare - $basic_dis_fare;
                }
            } else {
                if ($request->basic_dis_fare == 0) {
                    $final_cust_fare = $basic_dis_fare;
                    $sub_final_fare = $basic_dis_fare;
                } else {
                    $final_cust_fare = $final_max_fare;
                    $sub_final_fare = $final_max_fare - $basic_dis_fare;
                }
            }
        } else {
            $final_cust_fare = $basic_dis_fare;
            $sub_final_fare = 0;
        }

        if ($final_cust_fare > $final_max_fare) {
            $final_cust_fare = $final_max_fare;
            $sub_final_fare = $final_max_fare - $basic_dis_fare;
        }

        // 🎯 Force type casting to match old format
        $final_cust_fare = round($final_cust_fare);
        $awt_final_dis = (double) $dataAr1[0];
        $awt_final_dis = round($awt_final_dis);
        $str_awt_Fianl_dis = $awt_final_dis;
        $basic_dis_fare = (string) (int) $basic_dis_fare;
        // $final = (string) $sub_final_fare;

        $dataArr = array(
            "distance_string" => $str_awt_Fianl_dis,  // float
            "distance_amount" => $final_cust_fare,    // float
            "extra_fee" => $sub_final_fare,          // int
            "base_fee" => $basic_dis_fare             // string
        );

        return response()->json($dataArr);
    }
}
