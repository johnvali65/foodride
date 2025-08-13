<?php
namespace App\CentralLogics;

use App\Models\AddonGroup;
use App\Models\Admin;
use App\Models\DeliveryHistory;
use App\Models\DeliveryMan;
use App\Models\Option;
use App\Models\OrderDetail;
use App\Models\Restaurant;
use App\Models\ServiceOrderPidgeLog;
use App\Models\Tax;
use App\Models\Zone;
use App\Models\AddOn;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Order;
use App\Models\Review;
use App\Models\TimeLog;
use App\Models\Awtdeliverymantimestamp;
use App\Models\Currency;
use App\Models\DMReview;
use App\Mail\OrderPlaced;
use Exception;
use Http;
use Illuminate\Support\Carbon;
use App\Models\BusinessSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\CentralLogics\RestaurantLogic;
use Illuminate\Support\Facades\Storage;
use Laravelpkg\Laravelchk\Http\Controllers\LaravelchkController;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use GuzzleHttp\Exception\RequestException;
use Google\Auth\Credentials\ServiceAccountCredentials;

ini_set('max_execution_time', 360); // Set to 60 seconds

class Helpers
{
    public static function error_processor($validator)
    {
        $err_keeper = [];
        foreach ($validator->errors()->getMessages() as $index => $error) {
            array_push($err_keeper, ['code' => $index, 'message' => $error[0]]);
        }
        return $err_keeper;
    }

    public static function error_formater($key, $mesage, $errors = [])
    {
        $errors[] = ['code' => $key, 'message' => $mesage];

        return $errors;
    }

    public static function schedule_order()
    {
        return (bool) BusinessSetting::where(['key' => 'schedule_order'])->first()->value;
    }


    public static function combinations($arrays)
    {
        $result = [[]];
        foreach ($arrays as $property => $property_values) {
            $tmp = [];
            foreach ($result as $result_item) {
                foreach ($property_values as $property_value) {
                    $tmp[] = array_merge($result_item, [$property => $property_value]);
                }
            }
            $result = $tmp;
        }
        return $result;
    }

    public static function variation_price($product, $variation)
    {
        $match = json_decode($variation, true)[0];
        $result = 0;
        foreach (json_decode($product['variations'], true) as $property => $value) {
            if ($value['type'] == $match['type']) {
                $result = $value['price'];
            }
        }
        return $result;
    }

    public static function product_data_formatting($data, $multi_data = false, $trans = false, $local = 'en')
    {
        $storage = [];
        if ($multi_data == true) {
            foreach ($data as $item) {
                $variations = [];
                if ($item->title) {
                    $item['name'] = $item->title;
                    unset($item['title']);
                }
                if ($item->start_time) {
                    $item['available_time_starts'] = $item->start_time->format('H:i');
                    unset($item['start_time']);
                }
                if ($item->end_time) {
                    $item['available_time_ends'] = $item->end_time->format('H:i');
                    unset($item['end_time']);
                }

                if ($item->start_date) {
                    $item['available_date_starts'] = $item->start_date->format('Y-m-d');
                    unset($item['start_date']);
                }
                if ($item->end_date) {
                    $item['available_date_ends'] = $item->end_date->format('Y-m-d');
                    unset($item['end_date']);
                }
                $categories = [];
                foreach (json_decode($item['category_ids']) as $value) {
                    $categories[] = ['id' => (string) $value->id, 'position' => $value->position];
                }
                $item['category_ids'] = $categories;
                $item['attributes'] = json_decode($item['attributes']);
                $item['choice_options'] = json_decode($item['choice_options']);
                $item['petpooja_add_ons'] = self::petpooja_addon_data_formatting(
                    $item['petpooja_add_ons'],
                    $trans,
                    $local,
                    $item->veg ?? null
                );
                $item['add_ons'] = self::addon_data_formatting(AddOn::withoutGlobalScope('translate')->whereIn('id', json_decode($item['add_ons']))->active()->get(), true, $trans, $local);
                $item['options'] = self::option_data_formatting(Option::withoutGlobalScope('translate')->whereIn('id', json_decode($item['options']))->active()->get(), true, $trans, $local);
                foreach (json_decode($item['variations'], true) as $var) {
                    array_push($variations, [
                        'type' => $var['type'],
                        'price' => (float) $var['price']
                    ]);
                }
                $item['variations'] = $variations;
                $item['restaurant_name'] = $item->restaurant->name;
                $item['restaurant_discount'] = self::get_restaurant_discount($item->restaurant) ? $item->restaurant->discount->discount : 0;
                $item['restaurant_opening_time'] = $item->restaurant->opening_time ? $item->restaurant->opening_time->format('H:i') : null;
                $item['restaurant_closing_time'] = $item->restaurant->closeing_time ? $item->restaurant->closeing_time->format('H:i') : null;
                $item['restaurant_minimum_order'] = $item->restaurant->minimum_order;
                $item['restaurant_awt_item_pckg_charge'] = $item->restaurant->awt_item_pckg_charge;
                $item['restaurant_latitude'] = $item->restaurant->latitude;
                $item['restaurant_longitude'] = $item->restaurant->longitude;
                $item['schedule_order'] = $item->restaurant->schedule_order;
                $item['tax'] = $item->restaurant->tax;
                $item['rating_count'] = (int) ($item->rating ? array_sum(json_decode($item->rating, true)) : 0);
                //$item['avg_rating'] = (float)($item->avg_rating ? $item->avg_rating : 0);
                $awt_rating_avg = (float) ($item->avg_rating ? $item->avg_rating : 0);
                // $item['avg_rating'] = $ratings['rating'];
                $item['avg_rating'] = number_format($awt_rating_avg, 1);

                if ($trans) {
                    $item['translations'][] = [
                        'translationable_type' => 'App\Models\Food',
                        'translationable_id' => $item->id,
                        'locale' => 'en',
                        'key' => 'name',
                        'value' => $item->name
                    ];

                    $item['translations'][] = [
                        'translationable_type' => 'App\Models\Food',
                        'translationable_id' => $item->id,
                        'locale' => 'en',
                        'key' => 'description',
                        'value' => $item->description
                    ];
                }

                if (count($item['translations']) > 0) {
                    foreach ($item['translations'] as $translation) {
                        if ($translation['locale'] == $local) {
                            if ($translation['key'] == 'name') {
                                $item['name'] = $translation['value'];
                            }

                            if ($translation['key'] == 'title') {
                                $item['name'] = $translation['value'];
                            }

                            if ($translation['key'] == 'description') {
                                $item['description'] = $translation['value'];
                            }
                        }
                    }
                }
                if (!$trans) {
                    unset($item['translations']);
                }

                unset($item['restaurant']);
                unset($item['rating']);
                array_push($storage, $item);
            }
            $data = $storage;
        } else {
            $variations = [];
            $categories = [];
            foreach (json_decode($data['category_ids']) as $value) {
                $categories[] = ['id' => (string) $value->id, 'position' => $value->position];
            }
            $data['category_ids'] = $categories;
            // $data['category_ids'] = json_decode($data['category_ids']);
            $data['attributes'] = json_decode($data['attributes']);
            $data['choice_options'] = json_decode($data['choice_options']);
            $data['add_ons'] = self::addon_data_formatting(AddOn::whereIn('id', json_decode($data['add_ons']))->active()->get(), true, $trans, $local);
            $item['options'] = self::option_data_formatting(Option::whereIn('id', json_decode($data['options']))->active()->get(), true, $trans, $local);
            foreach (json_decode($data['variations'], true) as $var) {
                array_push($variations, [
                    'type' => $var['type'],
                    'price' => (float) $var['price']
                ]);
            }
            if ($data->title) {
                $data['name'] = $data->title;
                unset($data['title']);
            }
            if ($data->start_time) {
                $data['available_time_starts'] = $data->start_time->format('H:i');
                unset($data['start_time']);
            }
            if ($data->end_time) {
                $data['available_time_ends'] = $data->end_time->format('H:i');
                unset($data['end_time']);
            }
            if ($data->start_date) {
                $data['available_date_starts'] = $data->start_date->format('Y-m-d');
                unset($data['start_date']);
            }
            if ($data->end_date) {
                $data['available_date_ends'] = $data->end_date->format('Y-m-d');
                unset($data['end_date']);
            }
            $data['variations'] = $variations;
            $data['restaurant_name'] = $data->restaurant->name;
            $data['restaurant_discount'] = self::get_restaurant_discount($data->restaurant) ? $data->restaurant->discount->discount : 0;
            $data['restaurant_opening_time'] = $data->restaurant->opening_time ? $data->restaurant->opening_time->format('H:i') : null;
            $data['restaurant_closing_time'] = $data->restaurant->closeing_time ? $data->restaurant->closeing_time->format('H:i') : null;
            $data['schedule_order'] = $data->restaurant->schedule_order;
            $data['rating_count'] = (int) ($data->rating ? array_sum(json_decode($data->rating, true)) : 0);
            $data['avg_rating'] = (float) ($data->avg_rating ? $data->avg_rating : 0);

            if ($trans) {
                $data['translations'][] = [
                    'translationable_type' => 'App\Models\Food',
                    'translationable_id' => $data->id,
                    'locale' => 'en',
                    'key' => 'name',
                    'value' => $data->name
                ];

                $data['translations'][] = [
                    'translationable_type' => 'App\Models\Food',
                    'translationable_id' => $data->id,
                    'locale' => 'en',
                    'key' => 'description',
                    'value' => $data->description
                ];
            }

            if (count($data['translations']) > 0) {
                foreach ($data['translations'] as $translation) {
                    if ($translation['locale'] == $local) {
                        if ($translation['key'] == 'name') {
                            $data['name'] = $translation['value'];
                        }

                        if ($translation['key'] == 'title') {
                            $item['name'] = $translation['value'];
                        }

                        if ($translation['key'] == 'description') {
                            $data['description'] = $translation['value'];
                        }
                    }
                }
            }
            if (!$trans) {
                unset($data['translations']);
            }

            unset($data['restaurant']);
            unset($data['rating']);
        }

        return $data;
    }

    public static function addon_data_formatting($data, $multi_data = false, $trans = false, $local = 'en')
    {
        $storage = [];
        if ($multi_data == true) {
            foreach ($data as $item) {
                if ($trans) {
                    $item['translations'][] = [
                        'translationable_type' => 'App\Models\AddOn',
                        'translationable_id' => $item->id,
                        'locale' => 'en',
                        'key' => 'name',
                        'value' => $item->name
                    ];
                }
                if (count($item->translations) > 0) {
                    foreach ($item['translations'] as $translation) {
                        if ($translation['locale'] == $local && $translation['key'] == 'name') {
                            $item['name'] = $translation['value'];
                        }
                    }
                }

                if (!$trans) {
                    unset($item['translations']);
                }

                $storage[] = $item;
            }
            $data = $storage;
        } else if (isset($data)) {
            if ($trans) {
                $data['translations'][] = [
                    'translationable_type' => 'App\Models\AddOn',
                    'translationable_id' => $data->id,
                    'locale' => 'en',
                    'key' => 'name',
                    'value' => $data->name
                ];
            }

            if (count($data->translations) > 0) {
                foreach ($data['translations'] as $translation) {
                    if ($translation['locale'] == $local && $translation['key'] == 'name') {
                        $data['name'] = $translation['value'];
                    }
                }
            }

            if (!$trans) {
                unset($data['translations']);
            }
        }
        return $data;
    }

    public static function petpooja_addon_data_formatting($inputAddOns, $trans = false, $local = 'en', $defaultVeg=null)
    {
        // Check if $inputAddOns is a non-empty string
        if (!is_string($inputAddOns) || empty($inputAddOns)) {
            return [];
        }

        // Decode the JSON string into an array
        $decodedInputAddOns = json_decode($inputAddOns, true);

        // Check if decoding was successful and the result is an array
        if (!is_array($decodedInputAddOns)) {
            return [];
        }

        $grouped = [];

        // Create a mapping from addon_group_id to min/max selections
        $groupInfo = [];
        foreach ($decodedInputAddOns as $input) {
            $groupInfo[$input['addon_group_id']] = [
                'min_selection' => $input['addon_item_selection_min'],
                'max_selection' => $input['addon_item_selection_max'],
            ];
        }

        // Fetch add-on groups with names
        $addonGroupIds = collect($decodedInputAddOns)->pluck('addon_group_id')->toArray();

        // Fetch add-ons based on the addon_group_ids
        $add_ons = AddOn::withoutGlobalScope('translate')
            ->whereIn('addon_group_id', $addonGroupIds)
            ->active()
            ->orderBy('rank')
            ->get();

        // Debug: Log the fetched add-ons
        // info('Fetched add-ons:', $add_ons->toArray());

        // Initialize groups based on the input, even if they have no add-ons
        foreach ($addonGroupIds as $groupId) {
            $grouped[$groupId] = [
                'addon_group_id' => (int) $groupId,
                'add_on_group_name' => AddonGroup::where('id', $groupId)->value('group_name') ?? 'Group Name', // Get group_name from the addonGroups array
                'min_selection' => $groupInfo[$groupId]['min_selection'],
                'max_selection' => $groupInfo[$groupId]['max_selection'],
                'add_ons' => [],
            ];
        }

        // Group the add-ons by addon_group_id
        foreach ($add_ons as $addon) {
            $groupId = $addon->addon_group_id;
            $grouped[$groupId]['add_ons'][] = [
                'add_on_id' => $addon->id,
                'add_on_group_id' => $groupId,
                'add_on_name' => $addon->name,
                'price' => $addon->price,
                'veg' => $addon->veg ?? $defaultVeg,
                'rank' => $addon->rank,
                'status' => $addon->status
            ];
        }

        // Debug: Log the grouped add-ons
        // info('Grouped add-ons:', $grouped);

        return array_values($grouped);
    }


    public static function option_data_formatting($data, $multi_data = false, $trans = false, $local = 'en')
    {
        $storage = [];
        if ($multi_data == true) {
            foreach ($data as $item) {
                if ($trans) {
                    $item['translations'][] = [
                        'translationable_type' => 'App\Models\Option',
                        'translationable_id' => $item->id,
                        'locale' => 'en',
                        'key' => 'name',
                        'value' => $item->name
                    ];
                }
                if (count($item->translations) > 0) {
                    foreach ($item['translations'] as $translation) {
                        if ($translation['locale'] == $local && $translation['key'] == 'name') {
                            $item['name'] = $translation['value'];
                        }
                    }
                }

                if (!$trans) {
                    unset($item['translations']);
                }

                $storage[] = $item;
            }
            $data = $storage;
        } else if (isset($data)) {
            if ($trans) {
                $data['translations'][] = [
                    'translationable_type' => 'App\Models\Option',
                    'translationable_id' => $data->id,
                    'locale' => 'en',
                    'key' => 'name',
                    'value' => $data->name
                ];
            }

            if (count($data->translations) > 0) {
                foreach ($data['translations'] as $translation) {
                    if ($translation['locale'] == $local && $translation['key'] == 'name') {
                        $data['name'] = $translation['value'];
                    }
                }
            }

            if (!$trans) {
                unset($data['translations']);
            }
        }
        return $data;
    }

    public static function category_data_formatting($data, $multi_data = false, $trans = false)
    {
        $storage = [];
        if ($multi_data == true) {
            foreach ($data as $item) {
                if (count($item->translations) > 0) {
                    $item->name = $item->translations[0]['value'];
                }

                if (!$trans) {
                    unset($item['translations']);
                }

                $storage[] = $item;
            }
            $data = $storage;
        } else if (isset($data)) {
            if (count($data->translations) > 0) {
                $data->name = $data->translations[0]['value'];
            }

            if (!$trans) {
                unset($data['translations']);
            }
        }
        return $data;
    }

    public static function basic_campaign_data_formatting($data, $multi_data = false)
    {
        $storage = [];
        if ($multi_data == true) {
            foreach ($data as $item) {
                $variations = [];

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
                if (count($item['restaurants']) > 0) {
                    $item['restaurants'] = self::restaurant_data_formatting($item['restaurants'], true);
                }

                array_push($storage, $item);
            }
            $data = $storage;
        } else {
            if ($data->start_date) {
                $data['available_date_starts'] = $data->start_date->format('Y-m-d');
                unset($data['start_date']);
            }
            if ($data->end_date) {
                $data['available_date_ends'] = $data->end_date->format('Y-m-d');
                unset($data['end_date']);
            }

            if (count($data['translations']) > 0) {
                $translate = array_column($data['translations']->toArray(), 'value', 'key');
                $data['title'] = $translate['title'];
                $data['description'] = $translate['description'];
            }
            if (count($data['restaurants']) > 0) {
                $data['restaurants'] = self::restaurant_data_formatting($data['restaurants'], true);
            }
        }

        return $data;
    }
    public static function restaurant_data_formatting($data, $multi_data = false)
    {
        $storage = [];
        if ($multi_data == true) {
            foreach ($data as $item) {
                if ($item->opening_time) {
                    $item['available_time_starts'] = $item->opening_time->format('H:i');
                    unset($item['opening_time']);
                }
                if ($item->closeing_time) {
                    $item['available_time_ends'] = $item->closeing_time->format('H:i');
                    unset($item['closeing_time']);
                }

                $ratings = RestaurantLogic::calculate_restaurant_rating($item['rating']);
                unset($item['rating']);
                $awt_rating_avg = $ratings['rating'];
                //$item['avg_rating'] = $ratings['rating'];
                $item['avg_rating'] = number_format($awt_rating_avg, 1);
                $item['awt_avg_rating'] = floatval(number_format($awt_rating_avg, 1));
                //  $item['avg_rating'] = floatval(number_format($awt_rating_avg,1));
                //dd($item['avg_rating']);exit;
                $item['rating_count '] = $ratings['total'];
                unset($item['campaigns']);
                unset($item['pivot']);
                array_push($storage, $item);
            }
            $data = $storage;
        } else {
            if ($data->opening_time) {
                $data['available_time_starts'] = $data->opening_time->format('H:i');
                unset($data['opening_time']);
            }
            if ($data->closeing_time) {
                $data['available_time_ends'] = $data->closeing_time->format('H:i');
                unset($data['closeing_time']);
            }
            $ratings = RestaurantLogic::calculate_restaurant_rating($data['rating']);
            unset($data['rating']);
            // $data['avg_rating'] = $ratings['rating'];
            $awt_rating_avg = $ratings['rating'];
            $data['avg_rating'] = number_format($awt_rating_avg, 1);
            $data['awt_avg_rating'] = floatval(number_format($awt_rating_avg, 1));
            $data['rating_count '] = $ratings['total'];
            unset($data['campaigns']);
            unset($data['pivot']);
        }

        return $data;
    }

    public static function wishlist_data_formatting($data, $multi_data = false)
    {
        $foods = [];
        $restaurants = [];
        if ($multi_data == true) {

            foreach ($data as $item) {
                if ($item->food) {
                    $foods[] = self::product_data_formatting($item->food, false, false, app()->getLocale());
                }
                if ($item->restaurant) {
                    $restaurants[] = self::restaurant_data_formatting($item->restaurant);
                }
            }
        } else {
            if ($data->food) {
                $foods[] = self::product_data_formatting($data->food, false, false, app()->getLocale());
            }
            if ($data->restaurant) {
                $restaurants[] = self::restaurant_data_formatting($data->restaurant);
            }
        }

        return ['food' => $foods, 'restaurant' => $restaurants];
    }

    //awt_order_data_formatting

    public static function awt_order_data_formatting($data, $multi_data = false)
    {
        $storage = [];

        if ($multi_data) {
            foreach ($data as $item) {
                //// dd($item['awt_return_order_reason']); dd($item['order_status']);exit;
                if ($item['awt_return_order_reason'] != "n/a") {
                    $item['order_status'] = "awt-return";
                }
                $custFormattVar1 = explode(" ", $item['created_at']);
                $custFormattVar2 = explode("-", $custFormattVar1[0]);
                $newDateStr = $custFormattVar2[2] . "-" . $custFormattVar2[1] . "-" . $custFormattVar2[0];
                $newTimeStr = $custFormattVar1[1];
                $item['custom_created_date'] = $newDateStr;
                $item['custom_created_time'] = $newTimeStr;
                // $item['custom_created_date']="1-1-2023";
                // $item['custom_created_date']="12:00 PM";
                if (isset($item['restaurant'])) {
                    $item['restaurant_name'] = $item['restaurant']['name'];
                    $item['restaurant_address'] = $item['restaurant']['address'];
                    $item['restaurant_phone'] = $item['restaurant']['phone'];
                    $item['restaurant_lat'] = $item['restaurant']['latitude'];
                    $item['restaurant_lng'] = $item['restaurant']['longitude'];
                    $item['restaurant_logo'] = $item['restaurant']['logo'];
                    $item['restaurant_delivery_time'] = $item['restaurant']['delivery_time'];
                    $item['vendor_id'] = $item['restaurant']['vendor_id'];
                    unset($item['restaurant']);
                } else {
                    $item['restaurant_name'] = null;
                    $item['restaurant_address'] = null;
                    $item['restaurant_phone'] = null;
                    $item['restaurant_lat'] = null;
                    $item['restaurant_lng'] = null;
                    $item['restaurant_logo'] = null;
                    $item['restaurant_delivery_time'] = null;
                }
                $item['food_campaign'] = 0;
                foreach ($item->details as $d) {
                    if ($d->item_campaign_id != null) {
                        $item['food_campaign'] = 1;
                    }
                }

                $item['delivery_address'] = $item->delivery_address ? json_decode($item->delivery_address, true) : null;
                $item['details_count'] = (int) $item->details->count();
                $awtMeterDistK = $item->awt_dm_tot_dis;
                $finalMeterDis = $awtMeterDistK / 1000;
                $item['awt_distance_kms'] = "" . $finalMeterDis . " KMS";
                $item['awt_distance_fare'] = $item->awt_dm_tot_fare;
                unset($item['details']);
                array_push($storage, $item);
            }
            $data = $storage;
        } else {
            $custFormattVar1 = explode(" ", $item['created_at']);
            $custFormattVar2 = explode("-", $custFormattVar1[0]);
            $newDateStr = $custFormattVar2[2] . "-" . $custFormattVar2[1] . "-" . $custFormattVar2[0];
            $newTimeStr = $custFormattVar1[1];
            $item['custom_created_date'] = $newDateStr;
            $item['custom_created_time'] = $newTimeStr;
            // $item['custom_created_date']="1-1-2023";
            // $item['custom_created_date']="12:00 PM";
            if (isset($data['restaurant'])) {
                $data['restaurant_name'] = $data['restaurant']['name'];
                $data['restaurant_address'] = $data['restaurant']['address'];
                $data['restaurant_phone'] = $data['restaurant']['phone'];
                $data['restaurant_lat'] = $data['restaurant']['latitude'];
                $data['restaurant_lng'] = $data['restaurant']['longitude'];
                $data['restaurant_logo'] = $data['restaurant']['logo'];
                $data['restaurant_delivery_time'] = $data['restaurant']['delivery_time'];
                $data['vendor_id'] = $data['restaurant']['vendor_id'];
                unset($data['restaurant']);
            } else {
                $data['restaurant_name'] = null;
                $data['restaurant_address'] = null;
                $data['restaurant_phone'] = null;
                $data['restaurant_lat'] = null;
                $data['restaurant_lng'] = null;
                $data['restaurant_logo'] = null;
                $data['restaurant_delivery_time'] = null;
            }

            $data['food_campaign'] = 0;
            foreach ($data->details as $d) {
                if ($d->item_campaign_id != null) {
                    $data['food_campaign'] = 1;
                }
            }
            $data['delivery_address'] = $data->delivery_address ? json_decode($data->delivery_address, true) : null;
            $data['details_count'] = (int) $data->details->count();
            unset($data['details']);
        }
        return $data;
    }


    public static function order_data_formatting($data, $multi_data = false)
    {
        $storage = [];

        if ($multi_data) {
            foreach ($data as $item) {
                //dd($item['created_at']);
                $custFormattVar1 = explode(" ", $item['created_at']);
                $custFormattVar2 = explode("-", $custFormattVar1[0]);
                $newDateStr = $custFormattVar2[2] . "-" . $custFormattVar2[1] . "-" . $custFormattVar2[0];
                $newTimeStr = $custFormattVar1[1];
                $item['custom_created_date'] = $newDateStr;
                $item['custom_created_time'] = $newTimeStr;
                // $item['custom_created_date'] = "1-1-2023";
                // $item['custom_created_date'] = "12:00 PM";
                if (isset($item['restaurant'])) {
                    $item['restaurant_name'] = $item['restaurant']['name'];
                    $item['restaurant_address'] = $item['restaurant']['address'];
                    $item['restaurant_phone'] = $item['restaurant']['phone'];
                    $item['restaurant_lat'] = $item['restaurant']['latitude'];
                    $item['restaurant_lng'] = $item['restaurant']['longitude'];
                    $item['restaurant_logo'] = $item['restaurant']['logo'];
                    $item['restaurant_delivery_time'] = $item['restaurant']['delivery_time'];
                    $item['vendor_id'] = $item['restaurant']['vendor_id'];
                    unset($item['restaurant']);
                } else {
                    $item['restaurant_name'] = null;
                    $item['restaurant_address'] = null;
                    $item['restaurant_phone'] = null;
                    $item['restaurant_lat'] = null;
                    $item['restaurant_lng'] = null;
                    $item['restaurant_logo'] = null;
                    $item['restaurant_delivery_time'] = null;
                }
                $item['food_campaign'] = 0;
                foreach ($item->details as $d) {
                    if ($d->item_campaign_id != null) {
                        $item['food_campaign'] = 1;
                    }
                }

                $item['delivery_address'] = $item->delivery_address ? json_decode($item->delivery_address, true) : null;
                $item['details_count'] = (int) $item->details->count();
                $awtMeterDistK = $item->awt_dm_tot_dis;
                $finalMeterDis = $awtMeterDistK / 1000;
                $item['awt_distance_kms'] = "" . $finalMeterDis . " KMS";
                $item['awt_distance_fare'] = $item->awt_dm_tot_fare;
                $item['awt_delivery_boy_base_fare'] = $item['awt_dm_base_fare'];
                $item['awt_delivery_boy_extra_fare'] = $item['awt_dm_extra_fare'];
                $item['awt_delivery_boy_total_fare'] = $item['awt_dm_base_fare'] + $item['awt_dm_extra_fare'];
                unset($item['details']);
                array_push($storage, $item);
            }
            $data = $storage;
        } else {
            // $custFormattVar1=explode(" ",$item['created_at']);
            // $custFormattVar2=explode("-",$custFormattVar1[0]);
            // $newDateStr=$custFormattVar2[2]."-".$custFormattVar2[1]."-".$custFormattVar2[0];
            // $newTimeStr=$custFormattVar1[1];
            // $item['custom_created_date']=$newDateStr;
            // $item['custom_created_time']=$newTimeStr;
            $item['custom_created_date'] = "1-1-2023";
            $item['custom_created_date'] = "12:00 PM";
            if (isset($data['restaurant'])) {
                $data['restaurant_name'] = $data['restaurant']['name'];
                $data['restaurant_address'] = $data['restaurant']['address'];
                $data['restaurant_phone'] = $data['restaurant']['phone'];
                $data['restaurant_lat'] = $data['restaurant']['latitude'];
                $data['restaurant_lng'] = $data['restaurant']['longitude'];
                $data['restaurant_logo'] = $data['restaurant']['logo'];
                $data['restaurant_delivery_time'] = $data['restaurant']['delivery_time'];
                $data['vendor_id'] = $data['restaurant']['vendor_id'];
                unset($data['restaurant']);
            } else {
                $data['restaurant_name'] = null;
                $data['restaurant_address'] = null;
                $data['restaurant_phone'] = null;
                $data['restaurant_lat'] = null;
                $data['restaurant_lng'] = null;
                $data['restaurant_logo'] = null;
                $data['restaurant_delivery_time'] = null;
            }

            $data['food_campaign'] = 0;
            foreach ($data->details as $d) {
                if ($d->item_campaign_id != null) {
                    $data['food_campaign'] = 1;
                }
            }
            $data['delivery_address'] = $data->delivery_address ? json_decode($data->delivery_address, true) : null;

            $data['details_count'] = (int) $data->details->count();
            unset($data['details']);
        }
        return $data;
    }

    public static function order_details_data_formatting($data)
    {
        $storage = [];
        foreach ($data as $item) {
            $item['add_ons'] = json_decode($item['add_ons']);
            $item['options'] = json_decode($item['options']);
            $item['variation'] = json_decode($item['variation']);
            $item['food_details'] = json_decode($item['food_details'], true);
            $item['price'] = $item['price'] - $item['total_add_on_price'];
            array_push($storage, $item);
        }
        $data = $storage;

        return $data;
    }

    public static function awt_order_details_data_formatting($data, $awt_notes)
    {
        $storage = [];
        foreach ($data as $item) {
            $item['add_ons'] = json_decode($item['add_ons']);
            $item['options'] = $item['options'] == '[]' ? null : json_decode($item['options']);
            $item['variation'] = json_decode($item['variation']);
            $item['food_details'] = json_decode($item['food_details'], true);
            $item['order_note'] = $awt_notes;
            array_push($storage, $item);
        }
        $data = $storage;

        return $data;
    }

    public static function deliverymen_list_formatting($data)
    {
        $storage = [];
        foreach ($data as $item) {
            $storage[] = [
                'id' => $item['id'],
                'name' => $item['f_name'] . ' ' . $item['l_name'],
                'image' => $item['image'],
                'lat' => $item->last_location ? $item->last_location->latitude : false,
                'lng' => $item->last_location ? $item->last_location->longitude : false,
                'location' => $item->last_location ? $item->last_location->location : '',
            ];
        }
        $data = $storage;

        return $data;
    }

    public static function address_data_formatting($data)
    {
        foreach ($data as $key => $item) {
            $point = new Point($item->latitude, $item->longitude);
            $data[$key]['zone_ids'] = array_column(Zone::contains('coordinates', $point)->latest()->get(['id'])->toArray(), 'id');
        }
        return $data;
    }

    public static function deliverymen_data_formatting($data)
    {
        $storage = [];
        foreach ($data as $item) {
            $item['avg_rating'] = (float) (count($item->rating) ? (float) $item->rating[0]->average : 0);
            $item['avg_rating_string'] = number_format($item['avg_rating'], 2);
            $item['rating_count'] = (int) (count($item->rating) ? $item->rating[0]->rating_count : 0);
            $item['lat'] = $item->last_location ? $item->last_location->latitude : null;
            $item['lng'] = $item->last_location ? $item->last_location->longitude : null;
            $item['location'] = $item->last_location ? $item->last_location->location : null;
            if ($item['rating']) {
                unset($item['rating']);
            }
            if ($item['last_location']) {
                unset($item['last_location']);
            }
            $storage[] = $item;
        }
        $data = $storage;

        return $data;
    }

    public static function get_business_settings($name, $json_decode = true)
    {
        $config = null;

        $paymentmethod = BusinessSetting::where('key', $name)->first();

        if ($paymentmethod) {
            $config = $json_decode ? json_decode($paymentmethod->value, true) : $paymentmethod->value;
        }

        return $config;
    }

    public static function currency_code()
    {
        return BusinessSetting::where(['key' => 'currency'])->first()->value;
    }

    public static function currency_symbol()
    {
        $currency_symbol = Currency::where(['currency_code' => Helpers::currency_code()])->first()->currency_symbol;
        return $currency_symbol;
    }

    public static function format_currency($value)
    {
        $currency_symbol_position = BusinessSetting::where(['key' => 'currency_symbol_position'])->first()->value;

        return $currency_symbol_position == 'right' ? number_format($value, config('round_up_to_digit')) . ' ' . self::currency_symbol() : self::currency_symbol() . ' ' . number_format($value, config('round_up_to_digit'));
    }
    public static function send_push_notif_to_device($fcm_token, $data)
    {
        $serviceAccountPath = base_path('config/service-account.json');

        if (!file_exists($serviceAccountPath)) {
            throw new Exception("Service account file not found at $serviceAccountPath");
        }

        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

        $credentials = new ServiceAccountCredentials($scopes, $serviceAccountPath);
        $token = $credentials->fetchAuthToken()['access_token'];

        $header = [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ];

        // FCM HTTP v1 endpoint
        $url = "https://fcm.googleapis.com/v1/projects/foodride-73f43/messages:send";

        if (isset($data['message'])) {
            $message = $data['message'];
        } else {
            $message = '';
        }
        if (isset($data['conversation_id'])) {
            $conversation_id = $data['conversation_id'];
        } else {
            $conversation_id = '';
        }
        if (isset($data['sender_type'])) {
            $sender_type = $data['sender_type'];
        } else {
            $sender_type = '';
        }

        if(!isset($data['android_channel_id'])) {
            $data['android_channel_id'] = '';
        }

        $message_ios = [
            'message' => [
                'token' => $fcm_token,  // Send to the specific device token
                'data' => [
                    'title' => (string) $data['title'],
                    'body' => (string) $data['description'],
                    'image' => (string) $data['image'],
                    'order_id' => (string) $data['order_id'],
                    'type' => (string) $data['type'],
                    'conversation_id' => (string) $conversation_id,
                    'sender_type' => (string) $sender_type,
                    'is_read' => '0',
                    'icon' => 'new',
                    'sound' => 'notification.wav',
                    'android_channel_id' => $data['android_channel_id'] ? $data['android_channel_id'] : 'stackfood'
                ],
                'notification' => [
                    'title' => $data['title'],
                    'body' => $data['description'],
                    'image' => $data['image']
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $data['title'],
                                'body' => $data['description'],
                            ],
                            'sound' => 'default',
                            'badge' => 1,
                            'content-available' => 1,  // Required for silent notifications
                        ]
                    ]
                ]
            ]
        ];

        // Send notification using cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message_ios));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);

        info('send_push_notif_to_device result is........' . json_encode($result));
        return $result;
    }

    public static function send_push_notif_to_device_ios($fcm_token, $data)
    {

        $serviceAccountPath = base_path('config/service-account.json');

        if (!file_exists($serviceAccountPath)) {
            throw new Exception("Service account file not found at $serviceAccountPath");
        }

        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

        $credentials = new ServiceAccountCredentials($scopes, $serviceAccountPath);
        $token = $credentials->fetchAuthToken()['access_token'];

        $header = [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ];

        // FCM HTTP v1 endpoint
        $url = "https://fcm.googleapis.com/v1/projects/foodride-73f43/messages:send";

        if (isset($data['message'])) {

            $message = $data['message'];

        } else {

            $message = '';

        }

        if (isset($data['conversation_id'])) {

            $conversation_id = $data['conversation_id'];

        } else {

            $conversation_id = '';

        }

        if (isset($data['sender_type'])) {

            $sender_type = $data['sender_type'];

        } else {

            $sender_type = '';

        }



        $notification = [

            "title" => $data['title'],

            "body" => $data['description'],

            "image" => $data['image']

        ];

        $data = [

            "title" => (string) $data['title'],

            "body" => (string) $data['description'],

            "image" => (string) $data['image'],

            "order_id" => (string) $data['order_id'],

            "type" => (string) $data['type'],

            "conversation_id" => (string) $conversation_id,

            "sender_type" => (string) $sender_type,

            "is_read" => '0'

        ];

        $message_ios = [
            'message' => [
                'token' => $fcm_token,  // Send to the specific device token
                'data' => $data,
                'notification' => $notification,
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $data['title'],
                                'body' => $data['description'],
                            ],
                            'sound' => 'default',
                            'badge' => 1,
                            'content-available' => 1,  // Required for silent notifications
                        ]
                    ]
                ]
            ]
        ];

        // Send notification using cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message_ios));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);

        info('ABCD result is........' . json_encode($result));
        return $result;

    }

    public static function send_push_notif_to_device_buzzer($fcm_token, $data)
    {
        info('send_push_notif_to_device_buzzer called');
        $serviceAccountPath = base_path('config/service-account.json');

        if (!file_exists($serviceAccountPath)) {
            throw new Exception("Service account file not found at $serviceAccountPath");
        }

        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

        $credentials = new ServiceAccountCredentials($scopes, $serviceAccountPath);
        $token = $credentials->fetchAuthToken()['access_token'];

        $header = [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ];

        // FCM HTTP v1 endpoint
        $url = "https://fcm.googleapis.com/v1/projects/foodride-73f43/messages:send";

        if (isset($data['message'])) {
            $message = $data['message'];
        } else {
            $message = '';
        }
        if (isset($data['conversation_id'])) {
            $conversation_id = $data['conversation_id'];
        } else {
            $conversation_id = '';
        }
        if (isset($data['sender_type'])) {
            $sender_type = $data['sender_type'];
        } else {
            $sender_type = '';
        }

        $message_ios = [
            'message' => [
                'token' => $fcm_token,  // Send to the specific device token
                'data' => [
                    'title' => (string) $data['title'],
                    'body' => (string) $data['description'],
                    'image' => (string) $data['image'],
                    'order_id' => (string) $data['order_id'],
                    'type' => (string) $data['type'],
                    'conversation_id' => (string) $conversation_id,
                    'sender_type' => (string) $sender_type,
                    'is_read' => '0',
                    'icon' => 'new',
                    'sound' => 'buzzer.wav',
                    // 'android_channel_id' => 'foodrestaurant',
                    'android_channel_id' => 'warning'
                ],
                // 'notification' => [
                //     'title' => $data['title'],
                //     'body' => $data['description'],
                //     'image' => $data['image']
                // ],
                // 'apns' => [
                //     'payload' => [
                //         'aps' => [
                //             'alert' => [
                //                 'title' => $data['title'],
                //                 'body' => $data['description'],
                //             ],
                //             'sound' => 'default',
                //             'badge' => 1,
                //             'content-available' => 1,  // Required for silent notifications
                //         ]
                //     ]
                // ]
            ]
        ];

        // Send notification using cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message_ios));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);

        info('send_push_notif_to_device_buzzer result is........' . json_encode($result));
        return $result;
    }

    public static function send_push_notif_to_topic($data, $topic, $type)
    {
        info('send_push_notif_to_topic was called');
        $serviceAccountPath = base_path('config/service-account.json');

        if (!file_exists($serviceAccountPath)) {
            throw new Exception("Service account file not found at $serviceAccountPath");
        }

        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

        $credentials = new ServiceAccountCredentials($scopes, $serviceAccountPath);
        $token = $credentials->fetchAuthToken()['access_token'];

        $header = [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ];

        // FCM HTTP v1 endpoint
        $url = "https://fcm.googleapis.com/v1/projects/foodride-73f43/messages:send";

        if (isset($data['message'])) {
            $message = $data['message'];
        } else {
            $message = '';
        }

        if (isset($data['order_id'])) {
            $data = [
                'title' => (string) $data['title'],
                'body' => (string) $data['description'],  // Use 'body' consistently
                'image' => (string) $data['image'],
                'order_id' => (string) $data['order_id'],
                'is_read' => '0',
                'type' => (string) $type,
                'android_channel_id' => $data['android_channel_id']
            ];
        } else {
            $data = [
                'title' => (string) $data['title'],
                'body' => (string) $data['description'],
                'image' => (string) $data['image'],
                'body_loc_key' => (string) $type,
                'is_read' => '0',
                'type' => (string) $type,
                'icon' => 'new',
                'android_channel_id' => $data['android_channel_id']
            ];
        }

        $message_ios = [
            'message' => [
                'topic' => $topic,  // Topic-based messaging for Android and iOS
                // 'notification' => [
                //     'title' => $data['title'],
                //     'body' => $data['body'],  // Use 'body' instead of 'description'
                //     'image' => $data['image'],
                // ],
                // 'notification' => null,
                // 'android' => [
                //     'notification' => [
                //         'click_action' => 'FLUTTER_CLICK_ACTION',
                //     ],
                //     'data' => $data,  // Pass $data for Android-specific use cases
                // ],
                // 'apns' => [
                //     'payload' => [
                //         'aps' => [
                //             'category' => 'NEW_MESSAGE_CATEGORY',
                //             'alert' => [
                //                 'title' => $data['title'],
                //                 'body' => $data['body'],  // Use 'body' instead of 'description'
                //             ],
                //             'sound' => 'default',
                //             'badge' => 1,
                //             'content-available' => 1,  // Ensures background notification
                //         ]
                //     ]
                // ],
                'data' => $data,  // Ensure 'data' is passed for both Android and iOS
            ]
        ];

        info('send_push_notif_to_topic payload is......' . json_encode($message_ios));
        // Send notification using cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message_ios));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);
        info('send_push_notif_to_topic result is.........' . json_encode($result));
        return $result;
    }

    public static function admin_send_push_notification($data, $topic, $type)
    {
        $serviceAccountPath = base_path('config/service-account.json');

        if (!file_exists($serviceAccountPath)) {
            throw new Exception("Service account file not found at $serviceAccountPath");
        }

        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

        $credentials = new ServiceAccountCredentials($scopes, $serviceAccountPath);
        $token = $credentials->fetchAuthToken()['access_token'];

        $header = [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ];

        // FCM HTTP v1 endpoint
        $url = "https://fcm.googleapis.com/v1/projects/foodride-73f43/messages:send";

        if (isset($data['message'])) {
            $message = $data['message'];
        } else {
            $message = '';
        }

        $message_ios = [
            'message' => [
                'topic' => $topic,  // Topic-based messaging for Android and iOS
                'notification' => [
                    'title' => $data['title'],
                    'body' => $data['description'],
                    'image' => $data['image'],
                ],
                'android' => [
                    'notification' => [
                        'click_action' => 'FLUTTER_CLICK_ACTION',
                    ]
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'category' => 'NEW_MESSAGE_CATEGORY',
                            'alert' => [
                                'title' => $data['title'],
                                'body' => $data['description'],
                            ],
                            'sound' => 'default',
                            'badge' => 1,
                            'content-available' => 1,  // Ensures background notification
                        ]
                    ]
                ],
                'data' => $data,
            ]
        ];

        // Send notification using cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message_ios));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
        // $key = BusinessSetting::where(['key' => 'push_notification_key'])->first()->value;
        // $url = "https://fcm.googleapis.com/fcm/send";
        // $header = array(
        //     "authorization: key=" . $key . "",
        //     "content-type: application/json"
        // );

        // if (isset($data['message'])) {
        //     $message = $data['message'];
        // } else {
        //     $message = '';
        // }

        // if (isset($data['order_id'])) {
        //     $postdata = '{
        //         "to" : "/topics/' . $topic . '",
        //         "mutable_content": true,
        //         "data" : {
        //             "title":"' . $data['title'] . '",
        //             "body" : "' . $data['description'] . '",
        //             "image" : "' . $data['image'] . '",
        //             "order_id":"' . $data['order_id'] . '",
        //             "is_read": 0,
        //             "type":"' . $type . '"
        //         },
        //         "notification" : {
        //             "title":"' . $data['title'] . '",
        //             "body" : "' . $data['description'] . '",
        //             "image" : "' . $data['image'] . '",
        //             "order_id":"' . $data['order_id'] . '",
        //             "title_loc_key":"' . $data['order_id'] . '",
        //             "body_loc_key":"' . $type . '",
        //             "type":"' . $type . '",
        //             "is_read": 0,
        //             "icon" : "new",
        //           }
        //     }';
        // } else {
        //     $postdata = '{
        //         "to" : "/topics/' . $topic . '",
        //         "mutable_content": true,
        //         "data" : {
        //             "title":"' . $data['title'] . '",
        //             "body" : "' . $data['description'] . '",
        //             "image" : "' . $data['image'] . '",
        //             "is_read": 0,
        //             "type":"' . $type . '",
        //         },
        //         "notification" : {
        //             "title":"' . $data['title'] . '",
        //             "body" : "' . $data['description'] . '",
        //             "image" : "' . $data['image'] . '",
        //             "body_loc_key":"' . $type . '",
        //             "type":"' . $type . '",
        //             "is_read": 0,
        //             "icon" : "new",
        //           }
        //     }';
        // }

        // // info("POST DATA. ".json_encode($postdata,true));
        // $ch = curl_init();
        // $timeout = 900;
        // curl_setopt($ch, CURLOPT_URL, $url);
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        // curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        // curl_setopt($ch, CURLOPT_HTTPHEADER, $header);

        // // Get URL content
        // $result = curl_exec($ch);
        // //info("PUSH RESULT - ".$result);
        // // close handle to release resources
        // curl_close($ch);
        // //  dd($result);exit;
        // return $result;
    }

    public static function awt_order_custom_status_push_notification()
    {

        $serviceAccountPath = base_path('config/service-account.json');

        if (!file_exists($serviceAccountPath)) {
            throw new Exception("Service account file not found at $serviceAccountPath");
        }

        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

        $credentials = new ServiceAccountCredentials($scopes, $serviceAccountPath);
        $token = $credentials->fetchAuthToken()['access_token'];

        $header = [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ];

        // FCM HTTP v1 endpoint
        $url = "https://fcm.googleapis.com/v1/projects/foodride-73f43/messages:send";

        $data = [
            'title' => "test title",
            'message' => "test msg",
            'type' => 'order_status',
            'image' => ''
        ];

        $awtCustTokenVal = User::select('id', 'f_name', 'cm_firebase_token')->where('id', 12)->first();
        $storage = [];
        foreach ($awtCustTokenVal as $cData) {
            array_push($storage, $cData->cm_firebase_token);
        }

        $registatoin_ids = $storage;

        // Prepare message data for sending notification
        $message_ios = [
            'message' => [
                'token' => count($registatoin_ids) > 0 ? $registatoin_ids[0] : $registatoin_ids[0], // Send to the first available token
                'notification' => [
                    'title' => $data['title'],
                    'body' => $data['message'],
                    'image' => '',
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $data['title'],
                                'body' => $data['message'],
                            ],
                            'sound' => 'default',
                            'badge' => 1,
                            'content-available' => 1,  // Required for silent notifications
                        ]
                    ]
                ],
                'data' => $data,
            ]
        ];

        // Send notification using cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message_ios));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);

        return $result;

        // $key = BusinessSetting::where(['key' => 'push_notification_key'])->first()->value;
        // $url = "https://fcm.googleapis.com/fcm/send";
        // $header = array(
        //     "authorization: key=" . $key . "",
        //     "content-type: application/json"
        // );

        // /*if(isset($data['message'])){
        //     $message = $data['message'];
        // }else{
        //     $message = '';
        // }*/

        // $data = [
        //     'title' => "test title",
        //     'message' => "test msg",
        //     'type' => 'order_status',
        //     'image' => ''
        // ];

        // $awtCustTokenVal = User::select('id', 'f_name', 'cm_firebase_token')->where('id', 12)->first();
        // $storage = [];
        // foreach ($awtCustTokenVal as $cData) {
        //     array_push($storage, $cData->cm_firebase_token);
        // }

        // $registatoin_ids = $storage;
        // // $registatoin_ids=array("dcYvu4peSE-1Y8JqwstAQI:APA91bEmp7e8ft5yTOlZe-syDSj3bm9uO6YjnSNu252_9xv7_TIqn6uM8QsSBxr1NQLwkNKVWBnMoYdlzlD0FiSknSuF_ovX7s_r8paeRsX-QGD7j369jFjQjS9asmuq6rXaNp-5om78","fnBQDBa9SV-EtcQ9SCnEnk:APA91bENltVyYAAIDuzl6pwfMUejJLHWxVQVZcpqBnJTZr5IEHOfCpIANl68ScuDOl3l2JwKTRUbqZTZLDrpsc7H_QPlm2YMBfua2Q7c1wiZon-vFArC0PTXN3evA7GxqiqJF7861_lT");
        // $postdata = array(
        //     'registration_ids' => $registatoin_ids,
        //     'data' => $data,
        // );
        // $ch = curl_init();
        // $timeout = 1000000;
        // curl_setopt($ch, CURLOPT_URL, $url);
        // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        // curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata));
        // curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        // $result = curl_exec($ch);
        // curl_close($ch);
        // //  dd($result);exit;
        // return $result;

        /*$key = BusinessSetting::where(['key' => 'push_notification_key'])->first()->value;
        $url = "https://fcm.googleapis.com/fcm/send";
        $header = array(
            "authorization: key=" . $key . "",
            "content-type: application/json"
        );

        $data=[
            'title'=>"28-1-2023 Title",
            'message'=>"28-1-2023 MSG",
            'type'=>'foodride_order_status',
            'image'=>'https://foodride.in/img/toplogo.svg'
        ];

           $registatoin_ids=array("ccRh-2l2TFexQTOaIeRhKe:APA91bE1cbGCJVr7YjIFmkTc7DVQx1t0JSbNRqo-L-eXesyZSwgQ7hqhlnoS__XXF0ArqT4_b6UQJCEi5ApIfEQV3XNqFeULbKrKegwI2R2io3AWtjhSoyXETmW4eOHqpPQ8kpR6NSH8","c2wiCLJSRfGex3Q_oxMfCj:APA91bFuqrFN3PTpHvSBxJy0b7nkPRTXlPyC1h-5HTNn45rvtxfpAf5sfsVYEBx3xKmZiQAQ3rOtejlsP9N6lqVtXSD60g9XgBfVASrdA7QH-WpSyTFN0yOl3PK6tVHdh9RpD6Dbx49C");
             $postdata = array(
                 'registration_ids' => $registatoin_ids,
                 'data' => $data,
             );
        $ch = curl_init();
        $timeout = 1000000;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;*/

    }

    // public static function awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status)
    // {
    //     info("calling the awt_order_push_cust_notif_to_topic helper->" . $user_id);
    //     // Initialize variables
    //     $awtPushTitleVar = '';
    //     $awtPushDesVar = '';
    //     $image_name = "https://web.foodride.in/storage/app/public/business/2022-09-28-6333678a5f440.png";
    //     $order = Order::findOrFail($order_id);

    //     // Define notification messages based on status
    //     switch ($awt_status) {
    //         case "pending":
    //             $awtPushTitleVar = "Order placed successfully…";
    //             $awtPushDesVar = "Awaiting for restaurant to confirm your order";
    //             break;
    //         case "confirmed":
    //             $awtPushTitleVar = "Order confirmed";
    //             $awtPushDesVar = "Your order is in the kitchen now";
    //             break;
    //         case "processing":
    //             $awtPushTitleVar = $order->restaurant->is_petpooja_linked_store == 0 ? "Cooking now!!!" : "Your Food is Ready";
    //             $awtPushDesVar = "Rider will be assigned soon";
    //             break;
    //         case "handover":
    //             $awtPushTitleVar = "Rider on Time !!! On Time!!!";
    //             $awtPushDesVar = "Rider picked up the order";
    //             break;
    //         case "picked_up":
    //             $awtPushTitleVar = "Riding safely to your door";
    //             $awtPushDesVar = "On the way";
    //             break;
    //         case "delivered":
    //             $awtPushTitleVar = "Enjoy your favourite food";
    //             $awtPushDesVar = "Delivered!! Delivered!!";
    //             break;
    //         case "refunded":
    //             $awtPushTitleVar = "Foodride Order Refunded";
    //             $awtPushDesVar = "Your Order Refunded Successfully";
    //             break;
    //         case "canceled":
    //             $awtPushTitleVar = "Oops!! Your order has been cancelled";
    //             $awtPushDesVar = "Tap to place a new order";
    //             break;
    //         default:
    //             // Handle default case or unknown statuses
    //             break;
    //     }

    //     // Construct notification data
    //     $data = [
    //         'title' => $awtPushTitleVar,
    //         'message' => $awtPushDesVar,
    //         'type' => 'foodride_order_notification',
    //         'image' => $image_name
    //     ];

    //     // Retrieve push notification key
    //     $key = BusinessSetting::where(['key' => 'push_notification_key'])->first()->value;
    //     $url = "https://fcm.googleapis.com/fcm/send";
    //     $header = [
    //         "authorization: key=" . $key . "",
    //         "content-type: application/json"
    //     ];

    //     // Retrieve user's Firebase token
    //     $user = User::select('id', 'f_name', 'cm_firebase_token', 'cm_firebase_token_ios')
    //         ->where('id', '=', $user_id)
    //         // ->where('cm_firebase_token', '!=', '@')
    //         ->first();
    //     info("User data->" . $user->cm_firebase_token_ios);
    //     if ($user) {
    //         // Prepare tokens for notification
    //         $storage = [];
    //         $storage_ios = [];
    //         if ($user->cm_firebase_token != '@' && $user->cm_firebase_token != '') {
    //             $storage[] = $user->cm_firebase_token;
    //         }
    //         if ($user->cm_firebase_token_ios != '@' && $user->cm_firebase_token_ios != '') {
    //             $storage_ios[] = $user->cm_firebase_token_ios;
    //         }

    //         $registration_ids = $storage;
    //         $registration_ids_ios = $storage_ios;
    //         info("registration ids->" . $user->cm_firebase_token);

    //         $notification = [
    //             "title" => $awtPushTitleVar,
    //             "body" => $awtPushDesVar,
    //             'image_url' => $image_name,
    //         ];

    //         // Prepare data for sending notification
    //         if (count($storage) > 0) {
    //             $postdata = [
    //                 'registration_ids' => $registration_ids,
    //                 'data' => $data,
    //             ];
    //         } else if (count($storage_ios) > 0) {
    //             $postdata = [
    //                 'registration_ids' => $registration_ids_ios,
    //                 'notification' => $notification,
    //                 'data' => $data,
    //             ];
    //         }
    //         if(count($storage) > 0 || count($storage_ios) > 0) {
    //             // Send notification using cURL
    //             $ch = curl_init();
    //             $timeout = 1000000;
    //             curl_setopt($ch, CURLOPT_URL, $url);
    //             curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    //             curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    //             curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    //             curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata));
    //             curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    //             $result = curl_exec($ch);
    //             curl_close($ch);
    //             return $result;
    //         }
    //         return 1;
    //     } else {
    //         // Handle case where user or tokens are not found
    //         return "User or tokens not found";
    //     }

    //     // info("userid->neworder->" . $user_id);
    //     // $image_name = "https://web.foodride.in/storage/app/public/business/2022-09-28-6333678a5f440.png";
    //     // /*$data=[
    //     //     'title'=>$request->notification_title,
    //     //     'message'=>$request->description,
    //     //     'type'=>'foodride_offers',
    //     //     'image'=>$image_name
    //     // ];	*/



    //     // if ($awt_status == "pending") {
    //     //     $awtPushTitleVar = "Order placed successfully…";
    //     //     $awtPushDesVar = "Awaiting for restaurant to confirm your order";
    //     // } elseif ($awt_status == "confirmed") {
    //     //     $awtPushTitleVar = "Order confirmed";
    //     //     $awtPushDesVar = "Your order is in kitchen now";
    //     // } elseif ($awt_status == "processing") {
    //     //     $awtPushTitleVar = "Cooking now!!!";
    //     //     $awtPushDesVar = "Rider will be assigned soon";
    //     // } elseif ($awt_status == "handover") {
    //     //     $awtPushTitleVar = "Rider on Time !!! On Time!!!";
    //     //     $awtPushDesVar = "Rider picked up the order";
    //     // } elseif ($awt_status == "picked_up") {
    //     //     $awtPushTitleVar = "Riding safely to your door";
    //     //     $awtPushDesVar = "on the way";
    //     // } elseif ($awt_status == "delivered") {
    //     //     $awtPushTitleVar = "Enjoy your favourite food";
    //     //     $awtPushDesVar = "Delivered!! Delivered!!";
    //     // } elseif ($awt_status == "refunded") {
    //     //     $awtPushTitleVar = "Foodride Order Refunded";
    //     //     $awtPushDesVar = "Your Order Refunded Successfully";
    //     // } elseif ($awt_status == "canceled") {
    //     //     $awtPushTitleVar = "Oops!! Your order has been cancelled";
    //     //     $awtPushDesVar = "Tap to place new order";
    //     // }


    //     // /*
    //     // pending
    //     // confirmed
    //     // processing
    //     // handover
    //     // picked_up
    //     // delivered
    //     // */
    //     // $data = [
    //     //     'title' => $awtPushTitleVar,
    //     //     'message' => $awtPushDesVar,
    //     //     'type' => 'foodride_order_notification',
    //     //     'image' => $image_name
    //     // ];

    //     // //  info("18-2-2023 function executed");
    //     // $key = BusinessSetting::where(['key' => 'push_notification_key'])->first()->value;
    //     // $url = "https://fcm.googleapis.com/fcm/send";
    //     // $header = array(
    //     //     "authorization: key=" . $key . "",
    //     //     "content-type: application/json"
    //     // );

    //     // if (isset($data['message'])) {
    //     //     $message = $data['message'];
    //     // } else {
    //     //     $message = '';
    //     // }
    //     // //$user_id=12;
    //     // $awtCustTokenVal = User::select('id', 'f_name', 'cm_firebase_token', 'cm_firebase_token_ios')->where('id', '=', $user_id)->where('cm_firebase_token', '!=', '@')->first();
    //     // $storage = [];

    //     // if ($awtCustTokenVal != '') {
    //     //     $newArrayAwtArr = $awtCustTokenVal->toArray();

    //     //     array_push($storage, $newArrayAwtArr['cm_firebase_token']);
    //     //     array_push($storage, $newArrayAwtArr['cm_firebase_token_ios']);
    //     // } else {
    //     //     $storage = [];
    //     // }
    //     // /*    exit;
    //     //     foreach($awtCustTokenVal as $cData){
    //     //        // info("2-1-2023-push-".$cData->id." - ".$cData->f_name." - ".$cData->cm_firebase_token);

    //     //         //array_push($storage,$cData->cm_firebase_token);
    //     //     }*/

    //     // $registatoin_ids = $storage;
    //     // $postdata = array(
    //     //     'registration_ids' => $registatoin_ids,
    //     //     'data' => $data,
    //     // );
    //     // $ch = curl_init();
    //     // $timeout = 1000000;
    //     // curl_setopt($ch, CURLOPT_URL, $url);
    //     // curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    //     // curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    //     // curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    //     // curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata));
    //     // curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
    //     // $result = curl_exec($ch);
    //     // curl_close($ch);
    //     // return $result;
    // }

    // Latest FCM HTTP V1
    public static function awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status)
    {
        info("calling the awt_order_push_cust_notif_to_topic helper->" . $user_id);
        // Initialize variables
        $awtPushTitleVar = '';
        $awtPushDesVar = '';
        $image_name = "https://web.foodride.in/storage/app/public/business/2022-09-28-6333678a5f440.png";
        $order = Order::findOrFail($order_id);

        // Define notification messages based on status
        switch ($awt_status) {
            case "pending":
                $awtPushTitleVar = "Order placed successfully…";
                $awtPushDesVar = "Awaiting for restaurant to confirm your order";
                break;
            case "confirmed":
                $awtPushTitleVar = "Order confirmed";
                $awtPushDesVar = "Your order is in the kitchen now";
                break;
            case "processing":
                $awtPushTitleVar = $order->restaurant->is_petpooja_linked_store == 0 ? "Cooking now!!!" : "Your Food is Ready";
                $awtPushDesVar = "Rider will be assigned soon";
                break;
            case "handover":
                $awtPushTitleVar = "Rider on Time !!! On Time!!!";
                $awtPushDesVar = "Rider picked up the order";
                break;
            case "picked_up":
                $awtPushTitleVar = "Riding safely to your door";
                $awtPushDesVar = "On the way";
                break;
            case "delivered":
                $awtPushTitleVar = "Enjoy your favourite food";
                $awtPushDesVar = "Delivered!! Delivered!!";
                break;
            case "refunded":
                $awtPushTitleVar = "Foodride Order Refunded";
                $awtPushDesVar = "Your Order Refunded Successfully";
                break;
            case "canceled":
                $awtPushTitleVar = "Oops!! Your order has been cancelled";
                $awtPushDesVar = "Tap to place a new order";
                break;
            default:
                // Handle default case or unknown statuses
                break;
        }

        // Construct notification data
        $data = [
            'title' => $awtPushTitleVar,
            'message' => $awtPushDesVar,
            'type' => 'foodride_order_notification',
            'image' => $image_name
        ];

        // Retrieve push notification key
        // $key = BusinessSetting::where(['key' => 'push_notification_key'])->first()->value;
        // $url = "https://fcm.googleapis.com/fcm/send";
        // $header = [
        //     "authorization: key=" . $key . "",
        //     "content-type: application/json"
        // ];

        $serviceAccountPath = base_path('config/service-account.json');

        if (!file_exists($serviceAccountPath)) {
            throw new Exception("Service account file not found at $serviceAccountPath");
        }

        $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];

        $credentials = new ServiceAccountCredentials($scopes, $serviceAccountPath);
        $token = $credentials->fetchAuthToken()['access_token'];

        $header = [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ];

        // FCM HTTP v1 endpoint
        $url = "https://fcm.googleapis.com/v1/projects/foodride-73f43/messages:send";

        // Retrieve user's Firebase token
        $user = User::select('id', 'f_name', 'cm_firebase_token', 'cm_firebase_token_ios')
            ->where('id', '=', $user_id)
            // ->where('cm_firebase_token', '!=', '@')
            ->first();
        info("User data->" . $user->cm_firebase_token_ios);
        if ($user) {
            // Prepare tokens for notification
            $storage = [];
            $storage_ios = [];
            if ($user->cm_firebase_token != '@' && $user->cm_firebase_token != '') {
                $storage[] = $user->cm_firebase_token;
            }
            if ($user->cm_firebase_token_ios != '@' && $user->cm_firebase_token_ios != '') {
                $storage_ios[] = $user->cm_firebase_token_ios;
            }

            $registration_ids = $storage;
            $registration_ids_ios = $storage_ios;
            $noti_tokens = array_merge($registration_ids, $registration_ids_ios);
            info("registration ids->" . $user->cm_firebase_token);
            $finalResult = [];

            foreach ($noti_tokens as $token) {
                // Prepare message data for sending notification
                $message_ios = [
                    'message' => [
                        // 'token' => count($storage_ios) > 0 ? $storage_ios[0] : $storage[0], // Send to the first available token
                        'token' => $token,
                        'notification' => [
                            'title' => $awtPushTitleVar,
                            'body' => $awtPushDesVar,
                            'image' => $image_name,
                        ],
                        'apns' => [
                            'payload' => [
                                'aps' => [
                                    'alert' => [
                                        'title' => $awtPushTitleVar,
                                        'body' => $awtPushDesVar,
                                    ],
                                    'sound' => 'default',
                                    'badge' => 1,
                                    'content-available' => 1,  // Required for silent notifications
                                ]
                            ]
                        ],
                        'data' => $data,
                    ]
                ];

                // Send notification using cURL
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message_ios));
                curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
                $result = curl_exec($ch);
                curl_close($ch);
                info("registration ids->" . json_encode($result));
                $finalResult[] = $result;
            }

            $finalResults = end($finalResult);
            info('result is......' . $finalResults);

            return $finalResults;
        } else {
            // Handle case where user or tokens are not found
            return "User or tokens not found";
        }
    }

    //public static function awt_cust_send_push_notif_to_topic($data,$title,$msg,$type)
    public static function awt_cust_send_push_notif_to_topic($data)
    {
        info("awt_cust_send_push_notif_to_topic function executed");

        // Return a response immediately to indicate that the request is accepted
        // You can customize this message or log as per your requirement
        $response = [
            'status' => 'success',
            'message' => 'Notification request is being processed in the background.'
        ];

        // Convert the data array to a JSON string to pass to the background process
        $dataJson = json_encode($data);

        // Create a command that executes the push notification in the background
        // "php artisan" or "php" followed by the PHP script that handles the notification logic
        $command = "php " . base_path('artisan') . " send:push-notifications '$dataJson' > /dev/null 2>&1 &";

        // Use exec() to run the command in the background
        exec($command);

        // Return an immediate response to the user
        return response()->json($response);
    }

    public static function markTokenAsInactive($token)
    {
        info('token is........' . $token);
        $update_fcm = User::where('cm_firebase_token', $token)->first();

        if ($update_fcm) {
            $update_fcm->cm_firebase_token = null;
            $update_fcm->save();
        } else {
            $update_fcm_ios = User::where('cm_firebase_token_ios', $token)->first();
            if ($update_fcm_ios) {
                $update_fcm_ios->cm_firebase_token_ios = null;
                $update_fcm_ios->save();
            }
        }
    }

    public static function send_push_notif_to_restaurant($data)
    {
        info('send_push_notif_to_restaurant called');

        // Return a response immediately to indicate that the request is accepted
        // You can customize this message or log as per your requirement
        $response = [
            'status' => 'success',
            'message' => 'Notification request is being processed in the background.'
        ];

        // Convert the data array to a JSON string to pass to the background process
        $dataJson = json_encode($data);

        // Create a command that executes the push notification in the background
        // "php artisan" or "php" followed by the PHP script that handles the notification logic
        $command = "php " . base_path('artisan') . " send:push-vendor-notifications '$dataJson' > /dev/null 2>&1 &";

        // Use exec() to run the command in the background
        exec($command);

        // Return an immediate response to the user
        return response()->json($response);
    }

    public static function rating_count($food_id, $rating)
    {
        return Review::where(['food_id' => $food_id, 'rating' => $rating])->count();
    }

    public static function dm_rating_count($deliveryman_id, $rating)
    {
        return DMReview::where(['delivery_man_id' => $deliveryman_id, 'rating' => $rating])->count();
    }

    public static function tax_calculate($food, $price)
    {
        if ($food['pet_pooja_tax_id']) {
            // For Petpooja Tax Calculations
            $tax_value = 0;
            $exploded_taxes = explode(',', $food['pet_pooja_tax_id']);
            foreach ($exploded_taxes as $value) {
                $tax_data = Tax::where('tax_id', $value)->first();
                if ($tax_data->tax_type == 'percentage') {
                    $tax_value += ($price * $tax_data->tax_percentage) / 100;
                } else {
                    $tax_value += $tax_data->tax_percentage;
                }
            }
            $price_tax = $tax_value;
        } else {
            $restaurant = Restaurant::findOrFail($food['restaurant_id']);
            if ($restaurant) {
                $price_tax = ($price / 100) * $restaurant->tax;
            } else {
                $price_tax = $food['tax'];
            }
        }
        return $price_tax;
    }

    public static function discount_calculate($product, $price)
    {
        if ($product['restaurant_discount']) {
            $price_discount = ($price / 100) * $product['restaurant_discount'];
        } else if ($product['discount_type'] == 'percent') {
            $price_discount = ($price / 100) * $product['discount'];
        } else {
            $price_discount = $product['discount'];
        }
        return $price_discount;
    }

    public static function get_product_discount($product)
    {
        $restaurant_discount = self::get_restaurant_discount($product->restaurant);
        if ($restaurant_discount) {
            $discount = $restaurant_discount['discount'] . ' %';
        } else if ($product['discount_type'] == 'percent') {
            $discount = $product['discount'] . ' %';
        } else {
            $discount = self::format_currency($product['discount']);
        }
        return $discount;
    }

    public static function product_discount_calculate($product, $price, $restaurant)
    {
        $restaurant_discount = self::get_restaurant_discount($restaurant);
        if (isset($restaurant_discount)) {
            $price_discount = ($price / 100) * $restaurant_discount['discount'];
        } else if ($product['discount_type'] == 'percent') {
            $price_discount = ($price / 100) * $product['discount'];
        } else {
            $price_discount = $product['discount'];
        }
        return $price_discount;
    }

    public static function get_price_range($product, $discount = false)
    {
        $lowest_price = $product->price;
        $highest_price = $product->price;

        foreach (json_decode($product->variations) as $key => $variation) {
            if ($lowest_price > $variation->price) {
                $lowest_price = round($variation->price, 2);
            }
            if ($highest_price < $variation->price) {
                $highest_price = round($variation->price, 2);
            }
        }
        if ($discount) {
            $lowest_price -= self::product_discount_calculate($product, $lowest_price, $product->restaurant);
            $highest_price -= self::product_discount_calculate($product, $highest_price, $product->restaurant);
        }
        $lowest_price = self::format_currency($lowest_price);
        $highest_price = self::format_currency($highest_price);

        if ($lowest_price == $highest_price) {
            return $lowest_price;
        }
        return $lowest_price . ' - ' . $highest_price;
    }

    public static function get_restaurant_discount($restaurant)
    {
        //dd($restaurant);
        if ($restaurant->discount) {
            if (date('Y-m-d', strtotime($restaurant->discount->start_date)) <= now()->format('Y-m-d') && date('Y-m-d', strtotime($restaurant->discount->end_date)) >= now()->format('Y-m-d') && date('H:i', strtotime($restaurant->discount->start_time)) <= now()->format('H:i') && date('H:i', strtotime($restaurant->discount->end_time)) >= now()->format('H:i')) {
                return [
                    'discount' => $restaurant->discount->discount,
                    'min_purchase' => $restaurant->discount->min_purchase,
                    'max_discount' => $restaurant->discount->max_discount
                ];
            }
        }
        return null;
    }

    public static function max_earning()
    {
        $data = Order::where(['order_status' => 'delivered'])->select('id', 'created_at', 'order_amount')
            ->get()
            ->groupBy(function ($date) {
                return Carbon::parse($date->created_at)->format('m');
            });

        $max = 0;
        foreach ($data as $month) {
            $count = 0;
            foreach ($month as $order) {
                $count += $order['order_amount'];
            }
            if ($count > $max) {
                $max = $count;
            }
        }
        return $max;
    }

    public static function max_orders()
    {
        $data = Order::select('id', 'created_at')
            ->get()
            ->groupBy(function ($date) {
                return Carbon::parse($date->created_at)->format('m');
            });

        $max = 0;
        foreach ($data as $month) {
            $count = 0;
            foreach ($month as $order) {
                $count += 1;
            }
            if ($count > $max) {
                $max = $count;
            }
        }
        return $max;
    }

    public static function order_status_update_message($status)
    {
        if ($status == 'pending') {
            $data = BusinessSetting::where('key', 'order_pending_message')->first()->value;
        } elseif ($status == 'confirmed') {
            $data = BusinessSetting::where('key', 'order_confirmation_msg')->first()->value;
        } elseif ($status == 'processing') {
            $data = BusinessSetting::where('key', 'order_processing_message')->first()->value;
        } elseif ($status == 'picked_up') {
            $data = BusinessSetting::where('key', 'out_for_delivery_message')->first()->value;
        } elseif ($status == 'handover') {
            $data = BusinessSetting::where('key', 'order_handover_message')->first()->value;
        } elseif ($status == 'delivered') {
            $data = BusinessSetting::where('key', 'order_delivered_message')->first()->value;
        } elseif ($status == 'delivery_boy_delivered') {
            $data = BusinessSetting::where('key', 'delivery_boy_delivered_message')->first()->value;
        } elseif ($status == 'accepted') {
            $data = BusinessSetting::where('key', 'delivery_boy_assign_message')->first()->value;
        } elseif ($status == 'canceled') {
            $data = BusinessSetting::where('key', 'order_cancled_message')->first()->value;
        } elseif ($status == 'refunded') {
            $data = BusinessSetting::where('key', 'order_refunded_message')->first()->value;
        } else {
            $data = '{"status":"0","message":""}';
        }

        $res = json_decode($data, true);

        if ($res['status'] == 0) {
            return 0;
        }
        return $res['message'];
    }

    public static function awt_send_order_notification($cust_data_var, $title_var, $msg_var, $order_request_var)
    {

        $data = [
            'title' => $title_var,
            'description' => $msg_var,
            'order_id' => 1,
            'image' => "https://web.foodride.in/storage/app/public/business/2022-09-28-6333678a5f440.png",
        ];
        /*		$order_modify = Order::where(['id' => $order->id])->Notpos()->first();
                   $order_modify->is_delivery_call_done = 1;
                   $order_modify->save();
               self::send_push_notif_to_topic($data, $order->restaurant->zone->deliveryman_wise_topic, 'order_request');*/

        // info("customized push section");
        $key = BusinessSetting::where(['key' => 'push_notification_key'])->first()->value;
        $url = "https://fcm.googleapis.com/fcm/send";
        $header = array(
            "authorization: key=" . $key . "",
            "content-type: application/json"
        );
        $message = $msg_var;
        // if(isset($data['message'])){
        //     $message = $data['message'];
        // }else{
        //     $message = '';
        // }
        // $cust_data_userid = $userid;
        /*$awtCustTokenVal = User::select('cm_firebase_token')->first();
        dd($awtCustTokenVal->toSql());exit;
        $storage=[];
        foreach($awtCustTokenVal as $cData){
            // info("2-1-2023-push-order-".$cData->id." - ".$cData->f_name." - ".$cData->cm_firebase_token);
            array_push($storage,$cData->cm_firebase_token);
        }*/
        $storage = array("fZ1K6n6uTwyY2A50h8DK6S:APA91bEkFFUgZNl8CO9VQL6L8FJMosSZDn9FaPYwG9zdhs30tD7Ly_4j_JKZIncckOI0uML3m5kWzdX3SHmJ8mGYtDuGty-eIgBHtj76Zm7DWk_H1VAEH2J7IF6vIOcVTRu9IA6Juz7-");
        $registatoin_ids = $storage;
        $notification = [
            "title" => $title_var,
            "body" => $msg_var,
            'image_url' => 'https://web.foodride.in/storage/app/public/business/2022-09-28-6333678a5f440.png',
        ];
        $postdata = array(
            'registration_ids' => $registatoin_ids,
            'notification' => $notification,
            'data' => $data,
        );
        $ch = curl_init();
        $timeout = 1000000;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);
        info("static notification result is..........." . $result);
        return $result;
    }

    public static function awt_alpha_manual_send_order_notification($order)
    {
        info("awt_alpha_manual_send_order_notification");
        try {
            $status = ($order->order_status == 'delivered' && $order->delivery_man) ? 'delivery_boy_delivered' : $order->order_status;
            $value = self::order_status_update_message($status);

            //    if ($order->order_type == 'delivery' && !$order->scheduled && $order->order_status == 'confirmed'  && ($order->payment_method != 'cash_on_delivery' || config('order_confirmation_model') == 'restaurant')) {
            if ($order->order_type == 'delivery' && !$order->scheduled && ($order->payment_method != 'cash_on_delivery' || config('order_confirmation_model') == 'restaurant')) {
                $data = [
                    'title' => translate('messages.order_push_title'),
                    'description' => translate('messages.new_order_push_description'),
                    'order_id' => $order->id,
                    'image' => '',
                ];
                if ($order->restaurant->self_delivery_system) {
                    // self::send_push_notif_to_topic($data, "restaurant_dm_" . $order->restaurant_id, 'order_request');
                } else {
                    self::send_push_notif_to_topic($data, $order->restaurant->zone->deliveryman_wise_topic, 'order_request');
                }
            }
            return true;
        } catch (\Exception $e) {
            // info($e);
        }
        return false;
    }

    public static function send_order_notification($order)
    {

        try {
            $status = ($order->order_status == 'delivered' && $order->delivery_man) ? 'delivery_boy_delivered' : $order->order_status;
            $value = self::order_status_update_message($status);
            if ($value && $order->customer) {
                info('Entered in Delivered');
                info("awt Push Order Notificaiotn Test 7-2-2023 - ");
                $data = [
                    'title' => translate('messages.order_push_title'),
                    'description' => $value,
                    'order_id' => $order->id,
                    'image' => '',
                    'type' => 'order_status',
                ];
                self::send_push_notif_to_device($order->customer->cm_firebase_token, $data);
                self::send_push_notif_to_device_ios($order->customer->cm_firebase_token_ios, $data);
                DB::table('user_notifications')->insert([
                    'data' => json_encode($data),
                    'user_id' => $order->user_id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                $cust_data = array("order_id" => $order->id, "user_id" => $order->user_id, "order_status" => $order->order_status);
                if ($order->order_status == "delivered") {
                    $title = "Your Order Delivered";
                    $msg = "Your Order Id " . $order->id . " is Delivered.";
                }
                // $awtCustPush = self::awt_cust_send_push_notif_to_topic($cust_data,$title,$msg,'order_request');
                $awtNotification1 = self::awt_send_order_notification($cust_data, $title, $msg, 'order_request');
            }

            if ($status == 'picked_up') {
                info('Entered in Picked_up');
                $data = [
                    'title' => translate('messages.order_push_title'),
                    'description' => translate('messages.picked_up_by_delivery_man'),
                    'order_id' => $order->id,
                    'image' => '',
                    'type' => 'order_status',
                ];
                self::send_push_notif_to_device($order->restaurant->vendor->firebase_token, $data);
                DB::table('user_notifications')->insert([
                    'data' => json_encode($data),
                    'vendor_id' => $order->restaurant->vendor_id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // if ($order->order_type == 'delivery' && !$order->scheduled && $order->order_status == 'pending' && $order->payment_method == 'cash_on_delivery' && config('order_confirmation_model') == 'deliveryman' && $order->order_type != 'take_away') {
            // if ($order->order_type == 'delivery' && !$order->scheduled && $order->order_status == 'pending' && config('order_confirmation_model') == 'deliveryman' && $order->order_type != 'take_away') {
            if ($order->order_type == 'delivery' && $order->order_status == 'pending' && config('order_confirmation_model') == 'deliveryman' && $order->order_type != 'take_away') {
                info('Entered in delivery, pending, order_confirmation_model = deliveryman, order_type != take_away');
                if ($order->restaurant->self_delivery_system) {
                    $data = [
                        'title' => translate('messages.order_push_title'),
                        'description' => translate('messages.new_order_push_received'),
                        'order_id' => $order->id,
                        'image' => '',
                        'type' => 'new_order',
                    ];
                    self::send_push_notif_to_device_buzzer($order->restaurant->vendor->firebase_token, $data);
                    DB::table('user_notifications')->insert([
                        'data' => json_encode($data),
                        'vendor_id' => $order->restaurant->vendor_id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                } else {
                    $data = [
                        'title' => translate('messages.order_push_title'),
                        'description' => translate('messages.new_order_push_received'),
                        'order_id' => $order->id,
                        'image' => '',
                    ];
                    self::send_push_notif_to_topic($data, $order->restaurant->zone->deliveryman_wise_topic, 'order_request');
                }
            }

            // if ($order->order_type == 'delivery' && !$order->scheduled && $order->order_status == 'pending' && $order->payment_method == 'cash_on_delivery' && config('order_confirmation_model') == 'restaurant') {
            // if ($order->order_type == 'delivery' && !$order->scheduled && $order->order_status == 'pending' && config('order_confirmation_model') == 'restaurant') {
            if ($order->order_type == 'delivery' && $order->order_status == 'pending' && config('order_confirmation_model') == 'restaurant') {
                //info("Foodride-Kamal-Order-Place-Notification");
                info('Entered in delivery, pending, order_confirmation_model = restaurant');
                $data = [
                    'title' => translate('messages.order_push_title'),
                    'description' => translate('messages.new_order_push_received'),
                    'order_id' => $order->id,
                    'image' => '',
                    'type' => 'new_order',
                ];
                self::send_push_notif_to_device_buzzer($order->restaurant->vendor->firebase_token, $data);
                DB::table('user_notifications')->insert([
                    'data' => json_encode($data),
                    'vendor_id' => $order->restaurant->vendor_id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // if (!$order->scheduled && (($order->order_type == 'take_away' && $order->order_status == 'pending') || ($order->payment_method != 'cash_on_delivery' && $order->order_status == 'confirmed'))) {
            if ((($order->order_type == 'take_away' && $order->order_status == 'pending') || ($order->payment_method != 'cash_on_delivery' && $order->order_status == 'confirmed'))) {
                info('Entered in take_away, pending, payment_method != cash_on_delivery, order_status = confirmed 1');
                $data = [
                    'title' => translate('messages.order_push_title'),
                    'description' => translate('messages.new_order_push_received'),
                    'order_id' => $order->id,
                    'image' => '',
                    'type' => 'new_order',
                ];
                // self::send_push_notif_to_device($order->restaurant->vendor->firebase_token, $data);
                DB::table('user_notifications')->insert([
                    'data' => json_encode($data),
                    'vendor_id' => $order->restaurant->vendor_id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            if ($order->order_status == 'confirmed' && $order->order_type != 'take_away' && config('order_confirmation_model') == 'deliveryman' && $order->payment_method == 'cash_on_delivery') {
                info('Entered in take_away, pending, payment_method != cash_on_delivery, order_status = confirmed 2');
                if ($order->restaurant->self_delivery_system) {
                    $data = [
                        'title' => translate('messages.order_push_title'),
                        'description' => translate('messages.new_order_push_received'),
                        'order_id' => $order->id,
                        'image' => '',
                    ];

                    // self::send_push_notif_to_topic($data, "restaurant_dm_" . $order->restaurant_id, 'new_order');
                } else {
                    $data = [
                        'title' => translate('messages.order_push_title'),
                        'description' => translate('messages.new_order_push_received'),
                        'order_id' => $order->id,
                        'image' => '',
                        'type' => 'new_order',
                    ];
                    self::send_push_notif_to_device_buzzer($order->restaurant->vendor->firebase_token, $data);
                    DB::table('user_notifications')->insert([
                        'data' => json_encode($data),
                        'vendor_id' => $order->restaurant->vendor_id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);

                    $title = "Your Order is Confirmed by Restaurant";
                    $msg = "Your Order Id " . $order->id . " is Confirmed.";

                    $details_data = [
                        'title' => $title,
                        'message' => $msg,
                        'type' => 'order_request',
                        'image' => 'https://web.foodride.in/storage/app/public/notification/2023-01-02-63b2c9b53d8f8.png'
                    ];

                    self::awt_cust_send_order_push_notif_to_topic($order->user_id, $details_data);
                }
            }

            // if ($order->order_type == 'delivery' && !$order->scheduled && $order->order_status == 'confirmed'  && ($order->payment_method != 'cash_on_delivery' || config('order_confirmation_model') == 'restaurant')) {
            if ($order->order_type == 'delivery' && $order->order_status == 'confirmed' && ($order->payment_method != 'cash_on_delivery' || config('order_confirmation_model') == 'restaurant')) {
                //info("KAMAL-1-TEST-ORDER-STATUS");
                info('Entered in delivery, confirmed, payment_method != cash_on_delivery, order_confirmation_model = restaurant');
                $data = [
                    'title' => translate('messages.order_push_title'),
                    'description' => translate('messages.new_order_push_received'),
                    'order_id' => $order->id,
                    'image' => ''
                ];
                if ($order->restaurant->self_delivery_system) {
                    $data['android_channel_id'] = 'normal';
                    info('if condition notification');
                    self::send_push_notif_to_topic($data, "restaurant_dm_" . $order->restaurant_id, 'order_request');
                } else {
                    $data['android_channel_id'] = 'warning';
                    info('else condition notification');
                    if ($order->restaurant->restaurant_type == 'subscribed') {
                        info('Entered in restaurant_type = subscribed');
                        // Send push notification
                        $deliveryMen = DeliveryMan::where('type', 'restaurant_wise')
                            ->where('restaurant_id', $order->restaurant_id)
                            ->whereNotNull('fcm_token')
                            ->where('is_deleted', 0)
                            ->where('status', 1)
                            ->where('active', 1)
                            ->get();

                        foreach ($deliveryMen as $dm) {
                            $data = [
                                'title' => translate('messages.order_push_title'),
                                'description' => translate('messages.new_order_push_received'),
                                'order_id' => $order->id,
                                'image' => '',
                                'type' => 'order_request',
                                'android_channel_id' => 'warning',
                                // optional:
                                // 'conversation_id' => '',
                                // 'sender_type' => '',
                            ];

                            self::send_push_notif_to_device($dm->fcm_token, $data);
                        }
                    } else {
                        info('Entered in restaurant_type != subscribed');
                        // Send push notification
                        self::send_push_notif_to_topic($data, $order->restaurant->zone->deliveryman_wise_topic, 'new_order');
                    }
                    // self::awt_order_custom_status_push_notification();
                    // $cust_data = array("order_id" => $order->id, "user_id" => $order->user_id, "order_status" => $order->order_status);
                    // if ($order->order_status == "confirmed") {
                    $title = "Your Order is Confirmed";
                    $msg = "Your Order Id " . $order->id . " is Confirmed.";
                    // }

                    $details_data = [
                        'title' => $title,
                        'message' => $msg,
                        'type' => 'order_request',
                        'image' => 'https://web.foodride.in/storage/app/public/notification/2023-01-02-63b2c9b53d8f8.png'
                    ];

                    $cust_data = array("order_id" => $order->id, "user_id" => $order->user_id, "order_status" => $order->order_status);

                    // $title = "Your Order Confirmed";
                    // $msg = "Your Order Id " . $order->id . " is Confirmed.";


                    $awtCustPush = self::awt_cust_send_order_push_notif_to_topic($order->user_id, $details_data);
                    $awtNotification1 = self::awt_send_order_notification($cust_data, $title, $msg, 'order_request');
                }
            }

            if (in_array($order->order_status, ['processing', 'handover']) && $order->delivery_man) {
                //info("order status changed");
                info('Entered in processing, handover, delivery_man');
                $data = [
                    'title' => translate('messages.order_push_title'),
                    'description' => $order->order_status == 'processing' ? translate('messages.Proceed_for_cooking') : translate('messages.ready_for_delivery'),
                    'order_id' => $order->id,
                    'image' => '',
                    'type' => 'order_status'
                ];
                self::send_push_notif_to_device($order->delivery_man->fcm_token, $data);
                DB::table('user_notifications')->insert([
                    'data' => json_encode($data),
                    'delivery_man_id' => $order->delivery_man->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                $cust_data = array("order_id" => $order->id, "user_id" => $order->user_id, "order_status" => $order->order_status);
                if ($order->order_status == "processing") {
                    if ($order->restaurant->is_petpooja_linked_store == 1) {
                        $title = "Your Food is Ready";
                        $msg = "Your Order Id " . $order->id . " is Ready.";
                    } else {
                        $title = "Your Order is Cooking";
                        $msg = "Your Order Id " . $order->id . " is Cooking.";
                    }
                } else if ($order->order_status == "handover") {
                    $title = "Delivery Partner Received Your Order";
                    $msg = "Your Order Id " . $order->id . " is on way of Delivery.";
                }

                $details_data = [
                    'title' => $title,
                    'message' => $msg,
                    'type' => 'order_request',
                    'image' => 'https://web.foodride.in/storage/app/public/notification/2023-01-02-63b2c9b53d8f8.png'
                ];
                // info("processing status msg".$msg);
                $awtCustPush = self::awt_cust_send_order_push_notif_to_topic($order->user_id, $details_data);

            }

            if ($order->order_status == 'confirmed' && $order->payment_method != 'cash_on_delivery' && config('mail.status')) {
                try {
                    Mail::to($order->customer->email)->send(new OrderPlaced($order->id));
                } catch (\Exception $ex) {
                    // info($ex);
                }
            }
            return true;
        } catch (\Exception $e) {
            // info($e);
        }
        return false;
    }

    public static function awt_cust_send_order_push_notif_to_topic($userid, $data)
    {
        // info("customized push section");
        $key = BusinessSetting::where(['key' => 'push_notification_key'])->first()->value;
        $url = "https://fcm.googleapis.com/fcm/send";
        $header = array(
            "authorization: key=" . $key . "",
            "content-type: application/json"
        );

        if (isset($data['message'])) {
            $message = $data['message'];
        } else {
            $message = '';
        }
        $cust_data_userid = $userid;
        /*$awtCustTokenVal = User::select('cm_firebase_token')->first();
        dd($awtCustTokenVal->toSql());exit;
        $storage=[];
        foreach($awtCustTokenVal as $cData){
            // info("2-1-2023-push-order-".$cData->id." - ".$cData->f_name." - ".$cData->cm_firebase_token);
            array_push($storage,$cData->cm_firebase_token);
        }*/
        $storage = array("dcYvu4peSE-1Y8JqwstAQI:APA91bEmp7e8ft5yTOlZe-syDSj3bm9uO6YjnSNu252_9xv7_TIqn6uM8QsSBxr1NQLwkNKVWBnMoYdlzlD0FiSknSuF_ovX7s_r8paeRsX-QGD7j369jFjQjS9asmuq6rXaNp-5om78");
        $registatoin_ids = $storage;
        $postdata = array(
            'registration_ids' => $registatoin_ids,
            'data' => $data,
        );
        $ch = curl_init();
        $timeout = 1000000;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postdata));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        $result = curl_exec($ch);
        curl_close($ch);
        //  dd($result);exit;
        return $result;


    }

    public static function day_part()
    {
        $part = "";
        $morning_start = date("h:i:s", strtotime("5:00:00"));
        $afternoon_start = date("h:i:s", strtotime("12:01:00"));
        $evening_start = date("h:i:s", strtotime("17:01:00"));
        $evening_end = date("h:i:s", strtotime("21:00:00"));

        if (time() >= $morning_start && time() < $afternoon_start) {
            $part = "morning";
        } elseif (time() >= $afternoon_start && time() < $evening_start) {
            $part = "afternoon";
        } elseif (time() >= $evening_start && time() <= $evening_end) {
            $part = "evening";
        } else {
            $part = "night";
        }

        return $part;
    }

    public static function env_update($key, $value)
    {
        $path = base_path('.env');
        if (file_exists($path)) {
            file_put_contents(
                $path,
                str_replace(
                    $key . '=' . env($key),
                    $key . '=' . $value,
                    file_get_contents($path)
                )
            );
        }
    }

    public static function env_key_replace($key_from, $key_to, $value)
    {
        $path = base_path('.env');
        if (file_exists($path)) {
            file_put_contents(
                $path,
                str_replace(
                    $key_from . '=' . env($key_from),
                    $key_to . '=' . $value,
                    file_get_contents($path)
                )
            );
        }
    }

    public static function remove_dir($dir)
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir . "/" . $object) == "dir")
                        Helpers::remove_dir($dir . "/" . $object);
                    else
                        unlink($dir . "/" . $object);
                }
            }
            reset($objects);
            rmdir($dir);
        }
    }

    public static function get_restaurant_id()
    {
        if (auth('vendor_employee')->check()) {
            return auth('vendor_employee')->user()->restaurant->id;
        }
        return auth('vendor')->user()->restaurants[0]->id;
    }

    public static function get_vendor_id()
    {
        if (auth('vendor')->check()) {
            return auth('vendor')->id();
        } else if (auth('vendor_employee')->check()) {
            return auth('vendor_employee')->user()->vendor_id;
        }
        return 0;
    }

    public static function get_vendor_data()
    {
        if (auth('vendor')->check()) {
            return auth('vendor')->user();
        } else if (auth('vendor_employee')->check()) {
            return auth('vendor_employee')->user()->vendor;
        }
        return 0;
    }

    public static function get_loggedin_user()
    {
        if (auth('vendor')->check()) {
            return auth('vendor')->user();
        } else if (auth('vendor_employee')->check()) {
            return auth('vendor_employee')->user();
        }
        return 0;
    }

    public static function get_restaurant_data()
    {
        if (auth('vendor_employee')->check()) {
            return auth('vendor_employee')->user()->restaurant;
        }
        return auth('vendor')->user()->restaurants[0];
    }

    public static function upload(string $dir, string $format, $image = null)
    {
        if ($image != null) {
            $imageName = \Carbon\Carbon::now()->toDateString() . "-" . uniqid() . "." . $format;
            if (!Storage::disk('public')->exists($dir)) {
                Storage::disk('public')->makeDirectory($dir);
            }
            Storage::disk('public')->put($dir . $imageName, file_get_contents($image));
            return $imageName;
        }
        // else {
        // $imageName = 'def.png';
        // }
        //return $imageName;
    }

    public static function update(string $dir, $old_image, string $format, $image = null)
    {
        if ($image == null) {
            return $old_image;
        }
        if (Storage::disk('public')->exists($dir . $old_image)) {
            Storage::disk('public')->delete($dir . $old_image);
        }
        $imageName = Helpers::upload($dir, $format, $image);
        return $imageName;
    }

    public static function format_coordiantes($coordinates)
    {
        $data = [];
        foreach ($coordinates as $coord) {
            $data[] = (object) ['lat' => $coord->getlat(), 'lng' => $coord->getlng()];
        }
        return $data;
    }

    public static function module_permission_check($mod_name)
    {
        if (!auth('admin')->user()->role) {
            return false;
        }

        if ($mod_name == 'zone' && auth('admin')->user()->zone_id) {
            return false;
        }

        $permission = auth('admin')->user()->role->modules;
        if (isset($permission) && in_array($mod_name, (array) json_decode($permission)) == true) {
            return true;
        }

        if (auth('admin')->user()->role_id == 1) {
            return true;
        }
        return false;
    }

    public static function employee_module_permission_check($mod_name)
    {
        if (auth('vendor')->check()) {
            if ($mod_name == 'reviews') {
                return auth('vendor')->user()->restaurants[0]->reviews_section;
            } else if ($mod_name == 'deliveryman') {
                return auth('vendor')->user()->restaurants[0]->self_delivery_system;
            } else if ($mod_name == 'pos') {
                return auth('vendor')->user()->restaurants[0]->pos_system;
            }
            return true;
        } else if (auth('vendor_employee')->check()) {
            $permission = auth('vendor_employee')->user()->role->modules;
            if (isset($permission) && in_array($mod_name, (array) json_decode($permission)) == true) {
                if ($mod_name == 'reviews') {
                    return auth('vendor_employee')->user()->restaurant->reviews_section;
                } else if ($mod_name == 'deliveryman') {
                    return auth('vendor_employee')->user()->restaurant->self_delivery_system;
                } else if ($mod_name == 'pos') {
                    return auth('vendor_employee')->user()->restaurant->pos_system;
                }
                return true;
            }
        }

        return false;
    }
    public static function calculate_addon_price($addons, $add_on_qtys)
    {
        $add_ons_cost = 0;
        $data = [];
        if ($addons) {
            foreach ($addons as $key2 => $addon) {
                if ($add_on_qtys == null) {
                    $add_on_qty = 1;
                } else {
                    $add_on_qty = $add_on_qtys[$key2];
                }
                $data[] = ['id' => $addon->id, 'name' => $addon->name, 'price' => $addon->price, 'quantity' => $add_on_qty];
                $add_ons_cost += $addon['price'] * $add_on_qty;
            }
            return ['addons' => $data, 'total_add_on_price' => $add_ons_cost];
        }
        return null;
    }
    public static function calculate_options_price($options, $option_qtys)
    {
        $options_cost = 0;
        $data = [];
        if ($options) {
            // foreach ($options as $key2 => $option) {
            if ($option_qtys == null) {
                $option_qty = 1;
            } else {
                $option_qty = $option_qtys;
            }
            $option = Option::where('id', $options)->first();
            $data = ['id' => $option->id, 'name' => $option->name, 'price' => $option->price, 'quantity' => $option_qty];
            $options_cost = $option['price'] * $option_qty;
            // }
            return ['options' => $data, 'total_options_price' => $options_cost];
        }
        return null;
    }

    public static function get_settings($name)
    {
        $config = null;
        $data = BusinessSetting::where(['key' => $name])->first();
        if (isset($data)) {
            $config = json_decode($data['value'], true);
            if (is_null($config)) {
                $config = $data['value'];
            }
        }
        return $config;
    }

    public static function setEnvironmentValue($envKey, $envValue)
    {
        $envFile = app()->environmentFilePath();
        $str = file_get_contents($envFile);
        $oldValue = env($envKey);
        if (strpos($str, $envKey) !== false) {
            $str = str_replace("{$envKey}={$oldValue}", "{$envKey}={$envValue}", $str);
        } else {
            $str .= "{$envKey}={$envValue}\n";
        }
        $fp = fopen($envFile, 'w');
        fwrite($fp, $str);
        fclose($fp);
        return $envValue;
    }

    // public static function requestSender()
    // {
    //     $client = new \GuzzleHttp\Client();
    //     $response = $client->get(route(base64_decode('YWN0aXZhdGlvbi1jaGVjaw==')));
    //     $data = json_decode($response->getBody()->getContents(), true);
    //     return $data;
    // }
    public static function requestSender()
    {
        $class = new LaravelchkController();
        $response = $class->actch();
        return json_decode($response->getContent(), true);
    }


    public static function insert_business_settings_key($key, $value = null)
    {
        $data = BusinessSetting::where('key', $key)->first();
        if (!$data) {
            DB::table('business_settings')->updateOrInsert(['key' => $key], [
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return true;
    }

    public static function get_language_name($key)
    {
        $languages = array(
            "af" => "Afrikaans",
            "sq" => "Albanian - shqip",
            "am" => "Amharic - አማርኛ",
            "ar" => "Arabic - العربية",
            "an" => "Aragonese - aragonés",
            "hy" => "Armenian - հայերեն",
            "ast" => "Asturian - asturianu",
            "az" => "Azerbaijani - azərbaycan dili",
            "eu" => "Basque - euskara",
            "be" => "Belarusian - беларуская",
            "bn" => "Bengali - বাংলা",
            "bs" => "Bosnian - bosanski",
            "br" => "Breton - brezhoneg",
            "bg" => "Bulgarian - български",
            "ca" => "Catalan - català",
            "ckb" => "Central Kurdish - کوردی (دەستنوسی عەرەبی)",
            "zh" => "Chinese - 中文",
            "zh-HK" => "Chinese (Hong Kong) - 中文（香港）",
            "zh-CN" => "Chinese (Simplified) - 中文（简体）",
            "zh-TW" => "Chinese (Traditional) - 中文（繁體）",
            "co" => "Corsican",
            "hr" => "Croatian - hrvatski",
            "cs" => "Czech - čeština",
            "da" => "Danish - dansk",
            "nl" => "Dutch - Nederlands",
            "en" => "English",
            "en-AU" => "English (Australia)",
            "en-CA" => "English (Canada)",
            "en-IN" => "English (India)",
            "en-NZ" => "English (New Zealand)",
            "en-ZA" => "English (South Africa)",
            "en-GB" => "English (United Kingdom)",
            "en-US" => "English (United States)",
            "eo" => "Esperanto - esperanto",
            "et" => "Estonian - eesti",
            "fo" => "Faroese - føroyskt",
            "fil" => "Filipino",
            "fi" => "Finnish - suomi",
            "fr" => "French - français",
            "fr-CA" => "French (Canada) - français (Canada)",
            "fr-FR" => "French (France) - français (France)",
            "fr-CH" => "French (Switzerland) - français (Suisse)",
            "gl" => "Galician - galego",
            "ka" => "Georgian - ქართული",
            "de" => "German - Deutsch",
            "de-AT" => "German (Austria) - Deutsch (Österreich)",
            "de-DE" => "German (Germany) - Deutsch (Deutschland)",
            "de-LI" => "German (Liechtenstein) - Deutsch (Liechtenstein)",
            "de-CH" => "German (Switzerland) - Deutsch (Schweiz)",
            "el" => "Greek - Ελληνικά",
            "gn" => "Guarani",
            "gu" => "Gujarati - ગુજરાતી",
            "ha" => "Hausa",
            "haw" => "Hawaiian - ʻŌlelo Hawaiʻi",
            "he" => "Hebrew - עברית",
            "hi" => "Hindi - हिन्दी",
            "hu" => "Hungarian - magyar",
            "is" => "Icelandic - íslenska",
            "id" => "Indonesian - Indonesia",
            "ia" => "Interlingua",
            "ga" => "Irish - Gaeilge",
            "it" => "Italian - italiano",
            "it-IT" => "Italian (Italy) - italiano (Italia)",
            "it-CH" => "Italian (Switzerland) - italiano (Svizzera)",
            "ja" => "Japanese - 日本語",
            "kn" => "Kannada - ಕನ್ನಡ",
            "kk" => "Kazakh - қазақ тілі",
            "km" => "Khmer - ខ្មែរ",
            "ko" => "Korean - 한국어",
            "ku" => "Kurdish - Kurdî",
            "ky" => "Kyrgyz - кыргызча",
            "lo" => "Lao - ລາວ",
            "la" => "Latin",
            "lv" => "Latvian - latviešu",
            "ln" => "Lingala - lingála",
            "lt" => "Lithuanian - lietuvių",
            "mk" => "Macedonian - македонски",
            "ms" => "Malay - Bahasa Melayu",
            "ml" => "Malayalam - മലയാളം",
            "mt" => "Maltese - Malti",
            "mr" => "Marathi - मराठी",
            "mn" => "Mongolian - монгол",
            "ne" => "Nepali - नेपाली",
            "no" => "Norwegian - norsk",
            "nb" => "Norwegian Bokmål - norsk bokmål",
            "nn" => "Norwegian Nynorsk - nynorsk",
            "oc" => "Occitan",
            "or" => "Oriya - ଓଡ଼ିଆ",
            "om" => "Oromo - Oromoo",
            "ps" => "Pashto - پښتو",
            "fa" => "Persian - فارسی",
            "pl" => "Polish - polski",
            "pt" => "Portuguese - português",
            "pt-BR" => "Portuguese (Brazil) - português (Brasil)",
            "pt-PT" => "Portuguese (Portugal) - português (Portugal)",
            "pa" => "Punjabi - ਪੰਜਾਬੀ",
            "qu" => "Quechua",
            "ro" => "Romanian - română",
            "mo" => "Romanian (Moldova) - română (Moldova)",
            "rm" => "Romansh - rumantsch",
            "ru" => "Russian - русский",
            "gd" => "Scottish Gaelic",
            "sr" => "Serbian - српски",
            "sh" => "Serbo-Croatian - Srpskohrvatski",
            "sn" => "Shona - chiShona",
            "sd" => "Sindhi",
            "si" => "Sinhala - සිංහල",
            "sk" => "Slovak - slovenčina",
            "sl" => "Slovenian - slovenščina",
            "so" => "Somali - Soomaali",
            "st" => "Southern Sotho",
            "es" => "Spanish - español",
            "es-AR" => "Spanish (Argentina) - español (Argentina)",
            "es-419" => "Spanish (Latin America) - español (Latinoamérica)",
            "es-MX" => "Spanish (Mexico) - español (México)",
            "es-ES" => "Spanish (Spain) - español (España)",
            "es-US" => "Spanish (United States) - español (Estados Unidos)",
            "su" => "Sundanese",
            "sw" => "Swahili - Kiswahili",
            "sv" => "Swedish - svenska",
            "tg" => "Tajik - тоҷикӣ",
            "ta" => "Tamil - தமிழ்",
            "tt" => "Tatar",
            "te" => "Telugu - తెలుగు",
            "th" => "Thai - ไทย",
            "ti" => "Tigrinya - ትግርኛ",
            "to" => "Tongan - lea fakatonga",
            "tr" => "Turkish - Türkçe",
            "tk" => "Turkmen",
            "tw" => "Twi",
            "uk" => "Ukrainian - українська",
            "ur" => "Urdu - اردو",
            "ug" => "Uyghur",
            "uz" => "Uzbek - o‘zbek",
            "vi" => "Vietnamese - Tiếng Việt",
            "wa" => "Walloon - wa",
            "cy" => "Welsh - Cymraeg",
            "fy" => "Western Frisian",
            "xh" => "Xhosa",
            "yi" => "Yiddish",
            "yo" => "Yoruba - Èdè Yorùbá",
            "zu" => "Zulu - isiZulu",
        );
        return array_key_exists($key, $languages) ? $languages[$key] : $key;
    }

    public static function get_view_keys()
    {
        $keys = BusinessSetting::whereIn('key', ['toggle_veg_non_veg', 'toggle_dm_registration', 'toggle_restaurant_registration'])->get();
        $data = [];
        foreach ($keys as $key) {
            $data[$key->key] = (bool) $key->value;
        }
        return $data;
    }

    public static function default_lang()
    {
        // if (strpos(url()->current(), '/api')) {
        //     $lang = App::getLocale();
        // } elseif (session()->has('local')) {
        //     $lang = session('local');
        // } else {
        //     $data = Helpers::get_business_settings('language');
        //     $code = 'en';
        //     $direction = 'ltr';
        //     foreach ($data as $ln) {
        //         if (array_key_exists('default', $ln) && $ln['default']) {
        //             $code = $ln['code'];
        //             if (array_key_exists('direction', $ln)) {
        //                 $direction = $ln['direction'];
        //             }
        //         }
        //     }
        //     session()->put('local', $code);
        //     Session::put('direction', $direction);
        //     $lang = $code;
        // }
        // return $lang;
        return 'en';
    }
    public static function generate_referer_code()
    {
        // $user_name = $user_name = explode('@',$user->email)[0];
        // $user_id = $user->id;
        //dd($user_id);
        // $uid_length = strlen($user->id);
        // if (strlen($user_name) > 10 - $uid_length) {
        //     $user_name = substr($user_name, 0, 10 - $uid_length);
        // } else if (strlen($user_name) < 10 - $uid_length) {
        // $user_id = $user_id * pow(10, ((10 - $uid_length) - strlen($user_name)));
        // }
        // return $user_name . $user_id;
        // return $user_id;

        $number = mt_rand(100000000000, 999999999999);
        // call the same function if the barcode exists already
        if (Helpers::randomNumberExists($number)) {
            return Helpers::generateUniqueOrderId();
        }

        // otherwise, it's valid and can be used
        return $number;
    }

    public static function randomNumberExists($number)
    {
        // query the database and return a boolean
        // for instance, it might look like this in Laravel
        return User::where('ref_code', $number)->exists();
    }



    public static function remove_invalid_charcaters($str)
    {
        return str_ireplace(['\'', '"', ',', ';', '<', '>', '?'], ' ', $str);
    }

    public static function set_time_log($user_id, $date, $online = null, $offline = null)
    {
        try {
            $time_log = TimeLog::where(['user_id' => $user_id, 'date' => $date])->first();

            if ($time_log && $time_log->online && $online)
                return true;

            if ($offline && $time_log) {
                $time_log->offline = $offline;
                $custAwtTime = (strtotime($offline) - strtotime($time_log->online)) / 60;
                $time_log->working_hour = number_format((float) $custAwtTime, 2, '.', '');
                $time_log->save();

                $custTimeStampAdd = new Awtdeliverymantimestamp;
                $custTimeStampAdd->dmts_d_id = $user_id;
                $custTimeStampAdd->dmts_time = $offline;
                $custTimeStampAdd->dmts_status = 0;
                $custTimeStampAdd->save();


                return true;
            }

            $time_log = new TimeLog;
            $time_log->date = $date;
            $time_log->user_id = $user_id;
            $time_log->offline = $offline;
            $time_log->online = $online;
            $time_log->save();

            $custTimeStampAdd = new Awtdeliverymantimestamp;
            $custTimeStampAdd->dmts_d_id = $user_id;
            $custTimeStampAdd->dmts_time = $online;
            $custTimeStampAdd->dmts_status = 1;
            $custTimeStampAdd->save();

            return true;
        } catch (\Exception $ex) {
            // info($ex);
        }
        return false;
    }

    public static function push_notification_export_data($data)
    {
        $format = [];
        foreach ($data as $key => $item) {
            $format[] = [
                '#' => $key + 1,
                translate('title') => $item['title'],
                translate('description') => $item['description'],
                translate('zone') => $item->zone ? $item->zone->name : translate('messages.all_zones'),
                translate('tergat') => $item['tergat'],
                translate('status') => $item['status']
            ];
        }
        return $format;
    }

    public static function export_zones($collection)
    {
        $data = [];

        foreach ($collection as $key => $item) {
            $data[] = [
                'Si' => $key + 1,
                translate('messages.zone') . ' ' . translate('messages.id') => $item['id'],
                translate('messages.name') => $item['name'],
                translate('messages.restaurants') => $item->restaurants->count(),
                translate('messages.deliveryman') => $item->deliverymen->count(),
                translate('messages.status') => $item['status']
            ];
        }

        return $data;
    }

    public static function export_restaurants($collection)
    {
        $data = [];

        foreach ($collection as $key => $item) {
            $data[] = [
                'Si' => $key + 1,
                translate('messages.restaurant_name') => $item['name'],
                translate('messages.owner_information') => $item->vendor->f_name . ' ' . $item->vendor->l_name,
                translate('messages.phone') => $item->vendor->phone,
                translate('messages.zone') => $item->zone->name,
                translate('messages.status') => $item['status']
            ];
        }

        return $data;
    }

    public static function export_restaurant_orders($collection)
    {
        $data = [];
        foreach ($collection as $key => $item) {
            $data[] = [
                'Si' => $key + 1,
                translate('messages.order_id') => $item['id'],
                translate('messages.order_date') => $item['created_at'],
                translate('messages.customer_name') => isset($item->customer) ? $item->customer->f_name . ' ' . $item->customer->l_name : null,
                translate('messages.phone') => isset($item->customer) ? $item->customer->phone : null,
                translate('messages.total_amount') => $item['order_amount'] . ' ' . Helpers::currency_symbol(),
                translate('messages.order_status') => $item['order_status']
            ];
        }
        return $data;
    }

    public static function export_restaurant_food($collection)
    {
        $data = [];
        foreach ($collection as $key => $item) {
            $data[] = [
                'Si' => $key + 1,
                translate('messages.name') => $item['name'],
                translate('messages.category') => $item->category,
                translate('messages.price') => $item['price'],
                translate('messages.status') => $item['status']
            ];
        }

        return $data;
    }

    public static function export_categories($collection)
    {
        $data = [];
        foreach ($collection as $key => $item) {
            $data[] = [
                'SL' => $key + 1,
                translate('messages.id') => $item['id'],
                translate('messages.name') => $item['name'],
                translate('messages.priority') => ($item['priority'] == 1) ? 'medium' : ((1) ? 'normal' : 'high'),
                translate('messages.status') => $item['status']
            ];
        }

        return $data;
    }

    public static function export_attributes($collection)
    {
        $data = [];
        foreach ($collection as $key => $item) {
            $data[] = [
                'SL' => $key + 1,
                translate('messages.id') => $item['id'],
                translate('messages.name') => $item['name'],
            ];
        }

        return $data;
    }

    public static function get_varient(array $variations, array $variation): array
    {
        $variations = array_column($variations, 'price', 'type');
        $variant = implode("-", $variation);
        return [['type' => $variant, 'price' => $variations[$variant]]];
    }

    public static function send_cmoon_sms($mobile, $message, $template_id)
    {
        try {
            $response = Http::get('http://login.smsmoon.com/API/sms.php', [
                'username' => 'foodride',
                'password' => 'vizag@123',
                'from' => 'FDRIDE',
                'to' => $mobile,
                'msg' => $message,
                'type' => 1,
                'dnd_check' => 0,
                'template_id' => $template_id,
            ]);

            // Check if the request was successful
            if ($response->successful()) {
                return $response->body();
            } else {
                // Log the response for further analysis
                \Log::error('SMS send failed', ['response' => $response->body()]);
                return 'Error: SMS send failed';
            }
        } catch (RequestException $e) {
            // Log the exception message
            \Log::error('HTTP request failed', ['error' => $e->getMessage()]);
            return 'Error: ' . $e->getMessage();
        }
    }

    public static function sendPidgeNotification($order)
    {
        $token = Helpers::generatePidgeToken();
        $create_order = Helpers::createPidgeOrder($order);
        // return $create_order;
        $order_update = Order::where('id', $order->id)->first();
        $order_update->pidge_task_create_response = $create_order;
        $order_update->delivery_partner = 'Pidge';
        $order_update->pidge_task_id = (string) $create_order['data'][$order->id];
        $order_update->save();

        $pidge_log = new ServiceOrderPidgeLog();
        $pidge_log->service_order_id = $order->id;
        $pidge_log->action_type = 'created';
        $pidge_log->action_triggered_at = date('Y-m-d H:i:s');
        $pidge_log->action_response = json_encode($create_order);
        $pidge_log->request_id = (string) $create_order['data'][$order->id];
        $pidge_log->ip_address = request()->ip();
        $pidge_log->save();

        // return $create_order;
    }
    public static function generatePidgeToken()
    {
        $pidgeBaseUrl = env('PIDGE_BASE_URL', 'https://api.pidge.in/');
        $pidgeUsername = env('PIDGE_USERNAME', 'foodride_4_2259_685');
        $pidgePassword = env('PIDGE_PASSWORD', 'ea7e51e5b7a8001e28');

        $apiBaseUrl = $pidgeBaseUrl . 'v1.0/store/channel/vendor/';

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($apiBaseUrl . 'login', [
                    'username' => $pidgeUsername,
                    'password' => $pidgePassword,
                ]);
        // return $response;
        if ($response->successful()) {
            $token_with_text = $response['data']['token'];
            $token = substr($token_with_text, 7);
            return $token;
        } else {
            // Handle the error case here
            return $response->json();
        }
    }

    public static function createPidgeOrder($order)
    {
        $admin = Admin::where('role_id', 1)->first();
        $json_delivery = json_decode($order->delivery_address);
        $customer = User::where('id', $order->user_id)->first();
        $awt_cart_items = $order->awtOrderCartDetails ? json_decode($order->awtOrderCartDetails) : null;
        $order_time = date('H:i', strtotime($order->confirmed));
        $new_time = date('H:i', strtotime($order_time . ' +15 minutes'));
        $delivery_slot = $order_time . '-' . $new_time;

        $pidgeBaseUrl = env('PIDGE_BASE_URL', 'https://api.pidge.in/');

        // Define the endpoint
        $endpoint = 'v1.0/store/channel/vendor/order';

        // Define your JSON data
        $orderData = [
            "channel" => "FoodRide",
            "sender_detail" => [
                "address" => [
                    "address_line_1" => $order->restaurant->address,
                    // "address_line_2" => "Sender address line 2",
                    // "label" => $order->restaurant->name,
                    // "landmark" => $order->restaurant->address,
                    "city" => Zone::where('id', $order->restaurant->zone_id)->value('name'),
                    "state" => "Andhra Pradesh",
                    "country" => "India",
                    "pincode" => preg_match('/\b(\d{6})\b/', $order->restaurant->address, $matches) ? $matches[1] : "531055",
                    "latitude" => (float) round($order->restaurant->latitude, 4),
                    // sender latitude
                    "longitude" => (float) round($order->restaurant->longitude, 4),
                    // sender longitude
                    // "instructions_to_reach" => "Use Restaurant GPS"
                ],
                "name" => $order->restaurant->name,
                "mobile" => $order->restaurant->phone,
                "email" => $order->restaurant->email
            ],
            "poc_detail" => [
                "name" => $admin->f_name,
                "mobile" => $admin->phone,
                "email" => $admin->email
            ],
            "trips" => [
                [
                    "receiver_detail" => [
                        "address" => [
                            "address_line_1" => str_replace("\n", ', ', $json_delivery->address),
                            // "address_line_2" => "Receiver address line 2",
                            // "label" => $json_delivery->contact_person_name,
                            // "landmark" => $json_delivery->address,
                            "city" => Zone::where('id', $order->zone_id)->value('name'),
                            "state" => "Andhra Pradesh",
                            "country" => "India",
                            "pincode" => preg_match('/\b(\d{6})\b/', $json_delivery->address, $matches) ? $matches[1] : "531055",
                            "latitude" => (float) round($json_delivery->latitude, 4),
                            // receiver latitude
                            "longitude" => (float) round($json_delivery->longitude, 4),
                            // receiver longitude
                            // "instructions_to_reach" => "Receiver GPS"
                        ],
                        "name" => $json_delivery->contact_person_name,
                        "mobile" => $json_delivery->contact_person_number,
                        "email" => $customer->email ?? 'test@gmail.com'
                    ],
                    // "packages" => [],
                    "source_order_id" => (string) $order->id,
                    "reference_id" => (string) $order->id,
                    "cod_amount" => $order->payment_method == 'cash_on_delivery' ? $order->order_amount - $order->wallet_amount : 0,
                    "bill_amount" => $order->order_amount,
                    "products" => [],
                    // "delivery_date" => date('Y-m-d', strtotime($order->confirmed)),
                    // "delivery_slot" => $delivery_slot
                ]
            ]
        ];
        // return $orderData;
        // foreach ($awt_cart_items as $item) {
        //     $package = [
        //         "label" => "package label",
        //         "quantity" => $item->quantity,
        //         "dead_weight" => 0,
        //         "volumetric_weight" => $item->price * $item->quantity,
        //         // Assuming volumetric weight is calculated this way
        //         "length" => 2,
        //         "breadth" => 2,
        //         "height" => 2
        //     ];

        //     // Add the package to the "packages" array in the trip
        //     $orderData['trips'][0]['packages'][] = $package; // Assuming you want to add packages to the first trip in the array
        // }

        $products = [];

        if ($awt_cart_items) {
            foreach ($awt_cart_items as $item) {
                $product = [
                    "name" => "Food Item Name",
                    // You can set the name as per your requirement
                    "sku" => (string) $item->food_id,
                    // You can use the food_id as a unique identifier
                    "price" => (float) $item->price,
                    // Convert price to a floating-point number
                    "quantity" => (int) $item->quantity,
                    // Convert quantity to an integer
                    "image_url" => "https://web.livework.in/web/storage/app/public/business/2022-09-28-6333678a5f440.png",
                    // Replace with the actual image URL
                    // Add any other relevant information about the food item
                ];

                $products[] = $product;
            }
        }

        // Update the "products" array in your $orderData
        $orderData['trips'][0]['products'] = $products;

        $token = Helpers::generatePidgeToken();
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ])->post($pidgeBaseUrl . $endpoint, $orderData);
        return $response->json();
    }

    public static function getPidgeOrderStatus($orderId)
    {
        $pidgeBaseUrl = env('PIDGE_BASE_URL', 'https://api.pidge.in/');
        $apiBaseUrl = $pidgeBaseUrl . 'v1.0/store/channel/vendor/';

        $token = Helpers::generatePidgeToken();
        // Define the headers as an associative array
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];

        // Build the URL with the order ID
        $url = $apiBaseUrl . 'order/' . $orderId;

        // Send the GET request with the specified headers
        $response = Http::withHeaders($headers)->get($url);

        if ($response->successful()) {
            // Handle the successful response here
            $response1 = $response->json();
            $order = Order::where('pidge_task_id', $orderId)->first();
            if ($response1['data']['status'] == 'OUT_FOR_PICKUP') {
                $order->order_status = 'accepted';
                $order->accepted = now();

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

                $order_id = $order['id'];
                $user_id = $order['user_id'];
                $awt_status = $order['order_status'];
                try {
                    Helpers::send_order_notification($order);
                    $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status);
                } catch (\Throwable $th) {
                    //throw $th;
                }
            } else if ($response1['data']['status'] == 'PICKED_UP') {
                $order->order_status = 'picked_up';
                $order->picked_up = now();

                $order_id = $order['id'];
                $user_id = $order['user_id'];
                $awt_status = $order['order_status'];
                try {
                    Helpers::send_order_notification($order);
                    $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status);
                } catch (\Throwable $th) {
                    //throw $th;
                }
            } else if ($response1['data']['status'] == 'DELIVERED') {
                $order->order_status = 'delivered';
                $order->delivered = now();

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

                $order->details->each(function ($item, $key) {
                    if ($item->food) {
                        $item->food->increment('order_count');
                    }
                });
                $order->customer->increment('order_count');
                $order->restaurant->increment('order_count');

                $order_id = $order['id'];
                $user_id = $order['user_id'];
                $awt_status = $order['order_status'];
                try {
                    Helpers::send_order_notification($order);
                    $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status);
                } catch (\Throwable $th) {
                    //throw $th;
                }
            }
            $order->save();
            info('Pidge-Status-check-cronjob-executed-successfully');

            return $response1['data']['status'];
        } else {
            // Handle the error case here
            return $response->json();
        }
    }
    public static function cancelPidgeOrder($orderId)
    {
        $pidgeBaseUrl = env('PIDGE_BASE_URL', 'https://api.pidge.in/');
        $apiBaseUrl = $pidgeBaseUrl . 'v1.0/store/channel/vendor';

        $token = Helpers::generatePidgeToken();
        // Define the headers as an associative array
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];

        // Build the URL with the order ID
        $url = $apiBaseUrl . '/' . $orderId . '/cancel';
        // Send the GET request with the specified headers
        $response = Http::withHeaders($headers)->post($url);

        if ($response->successful()) {
            // Handle the successful response here
            $response1 = $response->json();

            $order = Order::where('pidge_task_id', $orderId)->first();
            info('Pidge-Cancel-Order-executed-successfully');

            $pidge_log = new ServiceOrderPidgeLog();
            $pidge_log->service_order_id = $order->id;
            $pidge_log->action_type = 'canceled';
            $pidge_log->action_triggered_at = date('Y-m-d H:i:s');
            $pidge_log->action_response = json_encode($response1);
            $pidge_log->request_id = $orderId;
            $pidge_log->ip_address = request()->ip();
            $pidge_log->save();

            return $response1;
        } else {
            // Handle the error case here
            return $response->json();
        }
    }

    public static function petPoojaSaveOrder($order_id)
    {
        $order_data = Order::where('id', $order_id)->first();

        $store_row = Restaurant::where('status', 1)
            ->where('id', $order_data->restaurant_id)
            ->first();

        if ($store_row && $store_row->is_petpooja_linked_store == 0) {
            return response()->json(['success' => false, 'message' => 'Store not linked with Petpooja'], 400);
        }

        if ($store_row && $store_row->is_petpooja_linked_store) {
            $app_key = env('PETPOOJA_APP_KEY');
            $app_secret = env('PETPOOJA_APP_SECRET');
            $access_token = env('PETPOOJA_ACCESS_TOKEN');

            $order_type = 'H';
            $enable_delivery = 0;
            // if ($order_data->self_pick_accepted == 'yes') {
            //     $enable_delivery = 1;
            //     $order_type = 'P';
            // }

            $order_details = OrderDetail::where('order_id', $order_data->id)->get();
            $orderItems = [];
            $taxDetails = [];
            $discountDetails = [];
            $tax_data_array_final = [];

            foreach ($order_details as $value) {
                $addons = json_decode($value->add_ons, true) ?? [];

                $add_ons = array_map(function ($addon) {
                    if ($addon) {
                        $add_on_data = AddOn::where('id', $addon['id'])->first();
                    } else {
                        $add_on_data = "";
                    }
                    return [
                        'id' => $add_on_data ? (string) $add_on_data->item_id : "",
                        'name' => $addon['name'] ?? "",
                        'price' => (string) $addon['price'] ?? "",
                        'quantity' => $addon['quantity'] ?? "",
                        'group_id' => $add_on_data ? (string) $add_on_data->addon_group_id : "",
                        'group_name' => $add_on_data ? AddonGroup::where('group_id', $add_on_data->addon_group_id)->value('group_name') : ""
                    ];
                }, $addons);

                $pet_pooja_tax_id = explode(",", $value->food->pet_pooja_tax_id);
                $taxes_array_final = [];

                foreach ($pet_pooja_tax_id as $taxes) {
                    $taxx = Tax::where('tax_id', $taxes)
                        ->first();
                    $taxes_array = [];
                    $tax_data_array = [];

                    if ($taxx) {
                        $taxes_array['id'] = (string) $taxx->tax_id;
                        $taxes_array['name'] = $taxx->tax_name;
                        $taxes_array['amount'] = (string) (number_format(($taxx->tax_percentage * $value->price) / 100, 2) * $value->quantity);
                        // $tax_data_array['id'] = (string) $taxx->tax_id;
                        // $tax_data_array['title'] = $taxx->tax_name;
                        // $tax_data_array['type'] = $taxx->tax_type == 'percentage' ? 'P' : 'F';
                        // $tax_data_array['price'] = $taxx->tax_percentage;
                        // $tax_data_array['tax'] = strval(round(($taxx->tax_percentage * $value->price) / 100, 2));
                        // $tax_data_array['restaurant_liable_amt'] = "";

                        $taxes_array_final[] = $taxes_array;
                        // $tax_data_array_final[] = $tax_data_array;
                    }
                }

                $option_data = json_decode($value->options, true) ?? [];

                if (count($option_data) > 0) {
                    $variation_name = $option_data['name'];
                    $variation = Option::where('id', $option_data['id'])->select('variation_id', 'option_id')->first();
                    $variation_id = $variation->variation_id;
                    $item_id = $variation->option_id;
                } else {
                    $variation_name = "";
                    $variation_id = "";
                    $item_id = "";
                }

                $orderItem = [
                    'id' => $item_id ? (string) $item_id : (string) $value->food->petpooja_item_id,
                    'name' => $value->food->name,
                    'gst_liability' => 'restaurant',
                    'item_tax' => $taxes_array_final,
                    'item_discount' => (string) $value->discount_on_food,
                    'price' => (string) $value->price,
                    'final_price' => (string) ($value->price - $value->discount_on_food),
                    'quantity' => (string) $value->quantity,
                    'description' => "",
                    'variation_name' => $variation_name,
                    'variation_id' => (string) $variation_id,
                    'AddonItem' => [
                        'details' => $add_ons,
                    ],
                ];

                $orderItems[] = $orderItem;
                // Populate order details
            }

            $customer = User::select(
                'email',
                'f_name as name',
                'phone'
            )
                ->where('status', 1)
                ->where('id', $order_data->user_id)
                ->first();
            $restaurant = Restaurant::select(
                'name as res_name',
                'address',
                'phone as contact_information',
                'petpooja_store_id as restID'
            )
                ->where('status', 1)
                ->where('id', $order_data->restaurant_id)
                ->first();

            $json_delivery = json_decode($order_data->delivery_address);
            // $discount_data = [
            //     'id' => '',
            //     'title' => '',
            //     'type' => '',
            //     'price' => ''
            // ];

            $combinedTaxDetails = [];

            foreach ($orderItems as $item) {
                foreach ($item['item_tax'] as $tax) {
                    $taxPercentage = Tax::where('tax_id', $tax['id'])
                        ->value('tax_percentage');

                    if (isset($combinedTaxDetails[$tax['id']])) {
                        // Entry with the same 'id' exists, update its values
                        $combinedTaxDetails[$tax['id']]['tax'] += $tax['amount'];

                        if ($item['gst_liability'] == "restaurant") {
                            $combinedTaxDetails[$tax['id']]['restaurant_liable_amt'] += $tax['amount'];
                        }
                    } else {
                        // Create a new entry in $combinedTaxDetails
                        $entry = [
                            "id" => $tax['id'],
                            "title" => $tax['name'],
                            "type" => "P",
                            "price" => (string) $taxPercentage,
                            "tax" => $tax['amount'],
                            "restaurant_liable_amt" => ($item['gst_liability'] == "restaurant") ? $tax['amount'] : "0.00",
                        ];

                        $combinedTaxDetails[$tax['id']] = $entry;
                    }
                }
            }

            // Convert the combined associative array back to an indexed array
            $combinedTaxDetails = array_values($combinedTaxDetails);

            // Round 'tax' and 'restaurant_liable_amt' to 2 decimal places and convert to string
            foreach ($combinedTaxDetails as $key => $c_tax) {
                $combinedTaxDetails[$key]['tax'] = number_format($combinedTaxDetails[$key]['tax'], 2, '.', '');
                $combinedTaxDetails[$key]['restaurant_liable_amt'] = number_format($combinedTaxDetails[$key]['restaurant_liable_amt'], 2, '.', '');
            }

            $orderinfo = [
                'app_key' => $app_key,
                'app_secret' => $app_secret,
                'access_token' => $access_token,
                'orderinfo' => [
                    'OrderInfo' => [
                        'Restaurant' => [
                            'details' => [
                                'res_name' => $restaurant->res_name,
                                'address' => $restaurant->address,
                                'contact_information' => $restaurant->contact_information,
                                'restID' => $restaurant->restID
                            ]
                        ],
                        'Customer' => [
                            'details' => [
                                'email' => $customer->email,
                                'name' => $customer->name,
                                'address' => str_replace("\n", ', ', $json_delivery->address),
                                'phone' => $customer->phone,
                                'latitude' => (string) round($json_delivery->latitude, 6),
                                'longitude' => (string) round($json_delivery->longitude, 6),
                            ]
                        ],
                        'Order' => [
                            'details' => [
                                'orderID' => (string) $order_data->id,
                                'preorder_date' => date('Y-m-d', strtotime($order_data->schedule_at)),
                                'preorder_time' => date('H:i:s', strtotime($order_data->schedule_at)),
                                'service_charge' => "", // Populate service charge if applicable
                                'sc_tax_amount' => "", // Populate service charge tax amount if applicable
                                'delivery_charges' => number_format($order_data->delivery_charge, 2),
                                'dc_tax_amount' => "", // Populate delivery charge tax amount if applicable
                                'dc_gst_details' => [
                                    array(
                                        "gst_liable" => "vendor",
                                        "amount" => ""
                                    ),
                                    array(
                                        "gst_liable" => "restaurant",
                                        "amount" => ""
                                    )
                                ],
                                'packing_charges' => $order_data->awt_order_pckg_charge ? (string) $order_data->awt_order_pckg_charge : "", // Populate packing charges if applicable
                                'pc_tax_amount' => "", // Populate packing charge tax amount if applicable
                                'pc_gst_details' => [
                                    array(
                                        "gst_liable" => "vendor",
                                        "amount" => ""
                                    ),
                                    array(
                                        "gst_liable" => "restaurant",
                                        "amount" => ""
                                    )
                                ],
                                'order_type' => $order_type, // Order type: 'H' for home delivery, 'P' for self-pickup, 'D' for dine-in
                                'advanced_order' => "N", // Indicates if it's an advanced order
                                'payment_type' => ($order_data->payment_method == 'cash_on_delivery' ? 'COD' : ($order_data->payment_method == 'razor_pay' ? 'ONLINE' : 'OTHER')),
                                'table_no' => "", // Populate table number if applicable
                                'no_of_persons' => "", // Populate number of persons if applicable
                                'discount_total' => $order_data->restaurant_discount_amount != 0 ? $order_data->restaurant_discount_amount : "", // Total discount amount
                                'tax_total' => (string) $order_data->total_tax_amount, // Total tax amount
                                'discount_type' => "", // Type of discount if applicable
                                'total' => number_format($order_data->order_amount, 2), // Total order amount
                                'description' => $order_data->awt_delivery_notes, // Description of the order
                                'created_on' => $order_data->schedule_at, // Order creation timestamp
                                'enable_delivery' => $enable_delivery, // Indicates if delivery is enabled
                                "min_prep_time" => $order_data->processing_time ?? "",
                                "callback_url" => route('petpooja-callback'),
                                "collect_cash" => $order_data->payment_method == 'cash_on_delivery' ? number_format($order_data->order_amount, 2) : "",
                                "otp" => 9999
                            ]
                        ],
                        "OrderItem" => [
                            "details" => $orderItems
                        ],
                        "Tax" => [
                            "details" => $combinedTaxDetails
                        ],
                        // "Discount" => [
                        //     "details" => $discount_data
                        // ]
                    ],
                    'udid' => '',
                    'device_type' => 'Web',
                ]
            ];

            // return $orderinfo;
            // $post_data = json_encode($orderinfo);
            info('Petpooja Order Info: ' . json_encode($orderinfo));

            $response = Http::post('https://pponlineordercb.petpooja.com/save_order', $orderinfo);
            info('Petpooja Order Response: ' . $response->body());

            if ($response->successful()) {
                $result = $response->json();
                return response()->json(['success' => true, 'message' => 'Order saved successfully', 'data' => $result], 200);
            } else {
                return response()->json(['success' => false, 'message' => 'Failed to save order'], 500);
            }
        }
    }

    public static function petpoojaRiderStatusUpdate($order_id, $status)
    {
        // Fetch order data
        $order_data = Order::findOrFail($order_id);
        $pidge_data = ServiceOrderPidgeLog::where('service_order_id', $order_id)
            ->where('action_type', 'OUT_FOR_PICKUP')
            ->first();
        if ($pidge_data) {
            $pidge_delivery = json_decode($pidge_data['action_response'], true);
        } else {
            $pidge_delivery = '';
        }

        // Fetch restaurant data
        $store_row = Restaurant::where('status', 1)->where('id', $order_data->restaurant_id)->first();

        if ($store_row && $store_row->is_petpooja_linked_store == 0) {
            return response()->json(['success' => false, 'message' => 'Store is not linked with PetPooja'], 400);
        }

        if ($store_row && $store_row->is_petpooja_linked_store) {
            $app_key = env('PETPOOJA_APP_KEY');
            $app_secret = env('PETPOOJA_APP_SECRET');
            $access_token = env('PETPOOJA_ACCESS_TOKEN');

            $orderinfo = [
                "app_key" => $app_key,
                "app_secret" => $app_secret,
                "access_token" => $access_token,
                "order_id" => $order_data->id,
                "outlet_id" => $store_row->petpooja_store_id,
                "status" => $status,
                "rider_data" => [
                    "rider_name" => $pidge_delivery ? $pidge_delivery['fulfillment']['rider']['name'] : ($order_data->delivery_man ? $order_data->delivery_man->f_name . ' ' . $order_data->delivery_man->l_name : 'Test'),
                    "rider_phone_number" => $pidge_delivery ? $pidge_delivery['fulfillment']['rider']['mobile'] : ($order_data->delivery_man ? $order_data->delivery_man->phone : '9988998899')
                ],
                "external_order_id" => ""
            ];

            $response = Http::post('https://pponlineordercb.petpooja.com/rider_status_update', $orderinfo);

            return response()->json(json_decode($response->body()), $response->status());
        }
    }

    public static function petpoojaOrderStatusUpdate($order_id, $status, $reason = null)
    {
        // Fetch the order by reference ID
        $order = Order::where('id', $order_id)->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        // Fetch the associated restaurant
        $restaurant = Restaurant::where('status', 1)
            ->where('id', $order->restaurant_id)
            ->first();

        if ($restaurant && $restaurant->is_petpooja_linked_store == 0) {
            return response()->json(['error' => 'Store is not linked to Petpooja'], 403);
        }

        if ($restaurant && $restaurant->is_petpooja_linked_store) {

            $app_key = env('PETPOOJA_APP_KEY');
            $app_secret = env('PETPOOJA_APP_SECRET');
            $access_token = env('PETPOOJA_ACCESS_TOKEN');

            $orderInfo = [
                "app_key" => $app_key,
                "app_secret" => $app_secret,
                "access_token" => $access_token,
                "restID" => $restaurant->petpooja_store_id,
                "orderID" => "", // pass it blank, will be deprecated soon.
                "clientorderID" => $order_id,
                "cancelReason" => $reason,
                "status" => $status
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->post('https://pponlineordercb.petpooja.com/update_order_status', $orderInfo);

            if ($response->successful()) {
                return response()->json(['message' => 'Order status updated successfully']);
            } else {
                return response()->json(['error' => 'Failed to update order status'], $response->status());
            }
        }

        return response()->json(['error' => 'Invalid request'], 400);
    }

    public static function getPidgeCurrentLocationUpdate($orderId)
    {
        $pidgeBaseUrl = env('PIDGE_BASE_URL', 'https://api.pidge.in/');
        $apiBaseUrl = $pidgeBaseUrl . 'v1.0/store/channel/vendor/order/' . $orderId;

        $token = Helpers::generatePidgeToken();
        // Define the headers as an associative array
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ];

        // Build the URL with the order ID
        $url = $apiBaseUrl . '/fulfillment/tracking';

        // Send the GET request with the specified headers
        $response = Http::withHeaders($headers)->get($url);

        if ($response->successful()) {
            // Handle the successful response here
            $response1 = $response->json();
            // dd($response1);
            $order = Order::where('pidge_task_id', $orderId)->first();
            if ($order) {
                if (in_array($response1['data']['status'], ['OUT_FOR_PICKUP', 'PICKED_UP', 'DELIVERED'])) {
                    $delivery_histories = new DeliveryHistory();
                    $delivery_histories->order_id = $order->id;
                    $delivery_histories->delivery_man_id = NULL;
                    $delivery_histories->time = date('Y-m-d H:i:s');
                    $delivery_histories->longitude = $response1['data']['location']['latitude'];
                    $delivery_histories->latitude = $response1['data']['location']['longitude'];
                    $delivery_histories->location = $response1;
                    $delivery_histories->save();
                }

                info('Pidge-Current-Location-Update-successfully');
            }

            return $response1['data']['status'];
        } else {
            // Handle the error case here
            return $response->json();
        }
    }
}
