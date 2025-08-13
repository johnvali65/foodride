<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\RestaurantLogic;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

class RestaurantController extends Controller
{
    public function get_restaurants(Request $request, $filter_data = "all")
    {

        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $type = $request->query('type', 'all');
        $zone_id = json_decode($request->header('zoneId'), true);
        /**/
        $t = date('Y-m-d');
        $tDay = date("D", strtotime($t)) . "day";

        if ($tDay == "Sunday") {
            $tDayCount = 0;
        } else if ($tDay == "Monday") {
            $tDayCount = 1;
        } else if ($tDay == "Tueday") {
            $tDayCount = 2;
        } else if ($tDay == "Wednusday") {
            $tDayCount = 3;
        } else if ($tDay == "Thursday") {
            $tDayCount = 4;
        } else if ($tDay == "Friday") {
            $tDayCount = 5;
        } else if ($tDay == "Saturday") {
            $tDayCount = 6;
        } else {
            $tDayCount = 0;
        }
        $scheduled_day_no = $tDayCount;
        //  dd($tDay);
/**/
        $request['limit'] = 100;
        $restaurants = RestaurantLogic::get_restaurants($zone_id, $filter_data, $request['limit'], $request['offset'], $type, $scheduled_day_no);
        $restaurants['restaurants'] = Helpers::restaurant_data_formatting($restaurants['restaurants'], true);

        return response()->json($restaurants, 200);
    }

    public function get_non_active_restaurants(Request $request, $filter_data = "all")
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $type = $request->query('type', 'all');
        $zone_id = json_decode($request->header('zoneId'), true);
        $restaurants = RestaurantLogic::awt_get_nonactive_restaurants($zone_id, $filter_data, $request['limit'], $request['offset'], $type);
        $restaurants['restaurants'] = Helpers::restaurant_data_formatting($restaurants['restaurants'], true);

        return response()->json($restaurants, 200);
    }

    public function get_latest_restaurants(Request $request, $filter_data = "all")
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $type = $request->query('type', 'all');

        /**/
        $t = date('Y-m-d');
        $tDay = date("D", strtotime($t)) . "day";

        if ($tDay == "Sunday") {
            $tDayCount = 0;
        } else if ($tDay == "Monday") {
            $tDayCount = 1;
        } else if ($tDay == "Tueday") {
            $tDayCount = 2;
        } else if ($tDay == "Wednusday") {
            $tDayCount = 3;
        } else if ($tDay == "Thursday") {
            $tDayCount = 4;
        } else if ($tDay == "Friday") {
            $tDayCount = 5;
        } else if ($tDay == "Saturday") {
            $tDayCount = 6;
        } else {
            $tDayCount = 0;
        }
        $scheduled_day_no = $tDayCount;
        //  dd($tDay);
/**/

        $zone_id = json_decode($request->header('zoneId'), true);
        $restaurants = RestaurantLogic::get_latest_restaurants($zone_id, $request['limit'], $request['offset'], $type, $scheduled_day_no);
        $restaurants['restaurants'] = Helpers::restaurant_data_formatting($restaurants['restaurants'], true);

        return response()->json($restaurants['restaurants'], 200);
    }

    public function get_popular_restaurants(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }
        $type = $request->query('type', 'all');
        $zone_id = json_decode($request->header('zoneId'), true);
        /**/
        $t = date('Y-m-d');
        $tDay = date("D", strtotime($t)) . "day";

        if ($tDay == "Sunday") {
            $tDayCount = 0;
        } else if ($tDay == "Monday") {
            $tDayCount = 1;
        } else if ($tDay == "Tueday") {
            $tDayCount = 2;
        } else if ($tDay == "Wednusday") {
            $tDayCount = 3;
        } else if ($tDay == "Thursday") {
            $tDayCount = 4;
        } else if ($tDay == "Friday") {
            $tDayCount = 5;
        } else if ($tDay == "Saturday") {
            $tDayCount = 6;
        } else {
            $tDayCount = 0;
        }
        $scheduled_day_no = $tDayCount;
        //  dd($tDay);
/**/
        $restaurants = RestaurantLogic::get_popular_restaurants($zone_id, $request['limit'], $request['offset'], $type, $scheduled_day_no);
        $restaurants['restaurants'] = Helpers::restaurant_data_formatting($restaurants['restaurants'], true);

        return response()->json($restaurants['restaurants'], 200);
    }

    public function get_details($id)
    {
        $restaurant = RestaurantLogic::get_restaurant_details($id);
        if ($restaurant) {
            $category_ids = DB::table('food')
                ->join('categories', 'food.category_id', '=', 'categories.id')
                ->selectRaw('IF((categories.position = "0"), categories.id, categories.parent_id) as categories')
                ->where('food.restaurant_id', $id)
                ->where('categories.status', 1)
                ->groupBy('categories')
                ->get();
            // dd($category_ids->pluck('categories'));
            if ($restaurant->awt_item_pckg_charge == null) {
                $restaurant->awt_item_pckg_charge = 0;
            }
            $restaurant = Helpers::restaurant_data_formatting($restaurant);
            $restaurant['category_ids'] = array_map('intval', $category_ids->pluck('categories')->toArray());
        }
        return response()->json($restaurant, 200);
    }

    public function get_searched_restaurants(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }
        $validator = Validator::make($request->all(), [
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $type = $request->query('type', 'all');
        /**/
        $t = date('Y-m-d');
        $tDay = date("D", strtotime($t)) . "day";

        if ($tDay == "Sunday") {
            $tDayCount = 0;
        } else if ($tDay == "Monday") {
            $tDayCount = 1;
        } else if ($tDay == "Tueday") {
            $tDayCount = 2;
        } else if ($tDay == "Wednusday") {
            $tDayCount = 3;
        } else if ($tDay == "Thursday") {
            $tDayCount = 4;
        } else if ($tDay == "Friday") {
            $tDayCount = 5;
        } else if ($tDay == "Saturday") {
            $tDayCount = 6;
        } else {
            $tDayCount = 0;
        }
        $scheduled_day_no = $tDayCount;
        //  dd($tDay);
/**/
        $zone_id = json_decode($request->header('zoneId'), true);
        $restaurants = RestaurantLogic::search_restaurants($request['name'], $zone_id, $request->category_id, $request['limit'], $request['offset'], $type, $scheduled_day_no);
        $restaurants['restaurants'] = Helpers::restaurant_data_formatting($restaurants['restaurants'], true);
        return response()->json($restaurants, 200);
    }

    public function reviews(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'restaurant_id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $id = $request['restaurant_id'];


        $reviews = Review::with(['customer', 'food'])
            ->whereHas('food', function ($query) use ($id) {
                return $query->where('restaurant_id', $id);
            })
            ->active()->latest()->get();

        $storage = [];
        foreach ($reviews as $item) {
            $item['attachment'] = json_decode($item['attachment']);
            $item['food_name'] = null;
            $item['food_image'] = null;
            $item['customer_name'] = null;
            if ($item->food) {
                $item['food_name'] = $item->food->name;
                $item['food_image'] = $item->food->image;
                if (count($item->food->translations) > 0) {
                    $translate = array_column($item->food->translations->toArray(), 'value', 'key');
                    $item['food_name'] = $translate['name'];
                }
            }
            if ($item->customer) {
                $item['customer_name'] = $item->customer->f_name . ' ' . $item->customer->l_name;
            }

            unset($item['food']);
            unset($item['customer']);
            array_push($storage, $item);
        }

        return response()->json($storage, 200);
    }

    public function awt_get_details($id)
    {
        $restaurant = RestaurantLogic::get_restaurant_details($id);

        if ($restaurant) {
            // Query to fetch categories with product counts
            $categoryCounts = DB::table('food')
                ->join('categories', 'food.category_id', '=', 'categories.id')
                ->selectRaw('IF(categories.position = 0, categories.id, categories.parent_id) AS category_id, COUNT(*) as product_count')
                ->where('food.restaurant_id', $id)
                ->where('categories.status', 1)
                ->where('food.available_time_starts', '<=', now()->format('H:i:s'))
                ->where('food.available_time_ends', '>=', now()->format('H:i:s'))
                ->where('food.status', 1)
                ->groupBy('category_id', 'categories.position', 'categories.parent_id', 'categories.id')
                ->pluck('product_count', 'category_id')
                ->toArray();

            $categoryData = DB::table('categories')
                ->select('id', 'name')
                ->whereIn('id', array_keys($categoryCounts))
                ->orderBy('priority', 'ASC')
                ->get();

            foreach ($categoryData as $cat) {
                $cat->count = (string) ($categoryCounts[$cat->id] ?? 0);
            }

            $restaurant = Helpers::restaurant_data_formatting($restaurant);
            $restaurant['category_ids'] = $categoryData;
            $restaurant['recommended_total'] = array_sum($categoryCounts); // Recommended for you
        }

        return response()->json($restaurant, 200);
    }


    // public function get_product_rating($id)
    // {
    //     try {
    //         $product = Food::find($id);
    //         $overallRating = ProductLogic::get_overall_rating($product->reviews);
    //         return response()->json(floatval($overallRating[0]), 200);
    //     } catch (\Exception $e) {
    //         return response()->json(['errors' => $e], 403);
    //     }
    // }

}
