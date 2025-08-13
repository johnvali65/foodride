<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\ProductLogic;
use App\CentralLogics\RestaurantLogic;
use App\Http\Controllers\Controller;
use App\Models\AddOn;
use App\Models\AddonGroup;
use App\Models\Coupon;
use App\Models\Food;
use App\Models\Option;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\Restaurant;
use App\Models\TempCart;
use App\Models\TempCartItem;
use DB;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProductController extends Controller
{

    // public function awt_get_latest_products(Request $request)
    // {

    //     $validator = Validator::make($request->all(), [
    //         'restaurant_id' => 'required',
    //         'category_id' => 'required',
    //         'limit' => 'required',
    //         'offset' => 'required',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['errors' => Helpers::error_processor($validator)], 403);
    //     }

    //     $type = $request->query('type', 'all');

    //     $products = ProductLogic::awt_get_latest_products($request['limit'], $request['offset'], $request['restaurant_id'], $request['category_id'], $type);
    //     $products['products'] = Helpers::product_data_formatting($products['products'], true, false, app()->getLocale());
    //     return response()->json($products, 200);

    // }

    public function awt_get_latest_products(Request $request)
    {
        // if ($request->has('veg')) {
        $validator = Validator::make($request->all(), [
            'restaurant_id' => 'required|integer',
            'limit' => 'required|integer',
            'offset' => 'required|integer',
            'veg' => 'nullable|in:0,1',
            'category_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $type = $request->query('type', 'all');
        $veg = $request->query('veg', null);
        $categoryId = $request->query('category_id', null); // 👈 fetch from query

        $groupedProducts = ProductLogic::awt_get_grouped_latest_products(
            $request['limit'],
            $request['offset'],
            $request['restaurant_id'],
            $type,
            $veg,
            $categoryId
        );

        return response()->json($groupedProducts, 200);
        // } else {
        //     $validator = Validator::make($request->all(), [
        //         'restaurant_id' => 'required',
        //         'category_id' => 'required',
        //         'limit' => 'required',
        //         'offset' => 'required',
        //     ]);

        //     if ($validator->fails()) {
        //         return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        //     }

        //     $type = $request->query('type', 'all');

        //     $products = ProductLogic::awt_get_latest_products($request['limit'], $request['offset'], $request['restaurant_id'], $request['category_id'], $type);
        //     $products['products'] = Helpers::product_data_formatting($products['products'], true, false, app()->getLocale());
        //     return response()->json($products, 200);
        // }
    }
    public function awt_get_latest_products_ios(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'restaurant_id' => 'required',
            'category_id' => 'required',
            'limit' => 'required',
            'offset' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $type = $request->query('type', 'all');

        $products = ProductLogic::awt_get_latest_products($request['limit'], $request['offset'], $request['restaurant_id'], $request['category_id'], $type);
        $products['products'] = Helpers::product_data_formatting($products['products'], true, false, app()->getLocale());
        return response()->json($products, 200);

    }
    public function get_latest_products(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'restaurant_id' => 'required',
            'category_id' => 'required',
            'limit' => 'required',
            'offset' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $type = $request->query('type', 'all');

        $products = ProductLogic::get_latest_products($request['limit'], $request['offset'], $request['restaurant_id'], $request['category_id'], $type);
        $products['products'] = Helpers::product_data_formatting($products['products'], true, false, app()->getLocale());
        return response()->json($products, 200);
    }

    public function awt_get_searched_products(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }
        $validator = Validator::make($request->all(), [
            'name' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        // Get current day and time
        $currentDay = now()->dayOfWeekIso; // 1 (Mon) to 7 (Sun)
        $currentTime = now()->format('H:i:s');

        // Decode zone ID from header
        $zone_id = json_decode($request->header('zoneId'), true);

        // Search term & config
        $search = $request['name'];
        $type = $request->query('type', 'all');
        $limit = $request['limit'] ?? 10;
        $offset = $request['offset'] ?? 1;

        // 🧠 Get IDs of currently open restaurants
        $openRestaurantIds = Restaurant::whereIn('zone_id', $zone_id)
            ->active()
            ->type($type)
            ->whereHas('schedules', function ($query) use ($currentDay, $currentTime) {
                $query->where('day', $currentDay)
                    ->where(function ($q) use ($currentTime) {
                        // Handles regular hours and overnight (e.g., 18:00 - 02:00)
                        $q->where(function ($sub) use ($currentTime) {
                            $sub->where('opening_time', '<=', $currentTime)
                                ->where('closing_time', '>=', $currentTime);
                        })->orWhere(function ($sub) use ($currentTime) {
                            $sub->where('closing_time', '<', 'opening_time')
                                ->where(function ($inner) use ($currentTime) {
                                    $inner->where('opening_time', '<=', $currentTime)
                                        ->orWhere('closing_time', '>=', $currentTime);
                                });
                        });
                    });
            })
            ->pluck('id')
            ->toArray();

        // 🛑 If no restaurants are open, return empty response
        if (empty($openRestaurantIds)) {
            return response()->json([
                'total_size' => 0,
                'limit' => $limit,
                'offset' => $offset,
                'last_page' => 1,
                'products' => []
            ]);
        }

        $key = explode(' ', $request['name']);

        $sess_cart_id = $request->sess_cart_id ?? null;
        $user_id = $request->user_id ?? null;
        $temp_cart_items = collect();

        if ($sess_cart_id && $user_id) {
            $sess_cart_id = str_replace('%3A', ':', $sess_cart_id);
            $temp_cart = TempCart::where('sess_cart_id', $sess_cart_id)
                ->where('user_id', $user_id)
                ->where('status', 1)
                ->first();

            if ($temp_cart) {
                $temp_cart_items = TempCartItem::where('temp_cart_id', $temp_cart->id)
                    ->where('status', 1)
                    ->get()
                    ->keyBy('food_id');
            }
        }

        //->where('awt_buy_N_get_n_product_type','!=','applicable')
        $products = Food::active()->type($type)
            ->whereIn('restaurant_id', $openRestaurantIds)
            ->when($request->category_id, function ($query) use ($request) {
                $query->whereHas('category', function ($q) use ($request) {
                    return $q->whereId($request->category_id)
                        ->orWhere('parent_id', $request->category_id);
                });
            })
            ->when($request->restaurant_id, function ($query) use ($request) {
                return $query->where('restaurant_id', $request->restaurant_id);
            })
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
            })
            ->where('awt_buy_N_get_n_product_type', '!=', 'applicable')
            ->paginate($limit, ['*'], 'page', $offset);

        //   dd($products->items());exit;
        foreach ($products as $value) {
            // $value->image = url('/') . '/storage/app/public/product/' . $value->image;
            if ($value->image == '') {
                $value->image = '';
            }
        }
        $data = [
            'total_size' => $products->total(),
            'limit' => $limit,
            'offset' => $offset,
            'last_page' => $products->lastPage(),
            'products' => $products->items()
        ];

        $data['products'] = Helpers::product_data_formatting($products->items(), true, false, app()->getLocale());

        foreach ($data['products'] as &$product) {
            $product->quantity = isset($temp_cart_items[$product->id])
                ? $temp_cart_items[$product->id]->quantity
                : 0;
        }
        return response()->json($data, 200);
    }

    public function get_all_items(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $zone_id = json_decode($request->header('zoneId'), true);

        $type = $request->query('type', 'all');
        //->where('awt_buy_N_get_n_product_type','!=','applicable')
        $products = Food::active()->type($type)
            ->whereHas('restaurant', function ($q) use ($zone_id) {
                $q->whereIn('zone_id', $zone_id);
            })
            ->when($request->category_id, function ($query) use ($request) {
                $query->whereHas('category', function ($q) use ($request) {
                    return $q->whereId($request->category_id)->orWhere('parent_id', $request->category_id);
                });
            })
            ->when($request->restaurant_id, function ($query) use ($request) {
                return $query->where('restaurant_id', $request->restaurant_id);
            })
            // ->where(function ($q) use ($key) {
            //     foreach ($key as $value) {
            //         $q->orWhere('name', 'like', "%{$value}%");
            //     }
            // })
            ->where('awt_buy_N_get_n_product_type', '!=', 'applicable')
            ->get();
        //   dd($products->items());exit;
        foreach ($products as $value) {
            // $value->image = url('/') . '/storage/app/public/product/' . $value->image;
            if ($value->image == '') {
                $value->image = '';
            }
        }
        $data = [
            'products' => $products
        ];

        $data['products'] = Helpers::product_data_formatting($data['products'], true, false, app()->getLocale());
        return response()->json($data, 200);
    }

    // public function get_searched_products(Request $request)
    // {
    //     if (!$request->hasHeader('zoneId')) {
    //         $errors = [];
    //         array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
    //         return response()->json([
    //             'errors' => $errors
    //         ], 403);
    //     }
    //     $validator = Validator::make($request->all(), [
    //         'name' => 'required'
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['errors' => Helpers::error_processor($validator)], 403);
    //     }
    //     $zone_id = json_decode($request->header('zoneId'), true);

    //     $key = explode(' ', $request['name']);
    //     $search = $request['name'];

    //     $limit = $request['limit'] ?? 10;
    //     $offset = $request['offset'] ?? 1;

    //     $type = $request->query('type', 'all');

    //     $products = Food::active()->type($type)
    //         ->whereHas('restaurant', function ($q) use ($zone_id) {
    //             $q->whereIn('zone_id', $zone_id);
    //         })
    //         ->when($request->category_id, function ($query) use ($request) {
    //             $query->whereHas('category', function ($q) use ($request) {
    //                 return $q->whereId($request->category_id)->orWhere('parent_id', $request->category_id);
    //             });
    //         })
    //         ->when($request->restaurant_id, function ($query) use ($request) {
    //             return $query->where('restaurant_id', $request->restaurant_id);
    //         })
    //         // ->where(function ($q) use ($key) {
    //         //     foreach ($key as $value) {
    //         //         $q->orWhere('name', 'like', "%{$value}%");
    //         //     }
    //         // })
    //         ->where(function ($q) use ($search) {
    //             $q->orWhere('name', 'like', '%' . $search . '%');
    //         })
    //         ->paginate($limit, ['*'], 'page', $offset);
    //     foreach ($products as $value) {
    //         // $value->image = url('/') . '/storage/app/public/product/' . $value->image;
    //         if ($value->image == '') {
    //             $value->image = '';
    //         }
    //     }
    //     $data = [
    //         'total_size' => $products->total(),
    //         'limit' => $limit,
    //         'offset' => $offset,
    //         'last_page' => $products->lastPage(),
    //         'products' => $products->items()
    //     ];

    //     $data['products'] = Helpers::product_data_formatting($data['products'], true, false, app()->getLocale());
    //     return response()->json($data, 200);
    // }

    public function get_searched_products(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }
        $validator = Validator::make($request->all(), [
            'name' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        // Get current day and time
        $currentDay = now()->dayOfWeekIso; // 1 (Mon) to 7 (Sun)
        $currentTime = now()->format('H:i:s');

        // Decode zone ID from header
        $zone_id = json_decode($request->header('zoneId'), true);

        // Search term & config
        $search = $request['name'];
        $type = $request->query('type', 'all');
        $limit = $request['limit'] ?? 10;
        $offset = $request['offset'] ?? 1;

        // 🧠 Get IDs of currently open restaurants
        $openRestaurantIds = Restaurant::whereIn('zone_id', $zone_id)
            ->active()
            ->type($type)
            ->whereHas('schedules', function ($query) use ($currentDay, $currentTime) {
                $query->where('day', $currentDay)
                    ->where(function ($q) use ($currentTime) {
                        // Handles regular hours and overnight (e.g., 18:00 - 02:00)
                        $q->where(function ($sub) use ($currentTime) {
                            $sub->where('opening_time', '<=', $currentTime)
                                ->where('closing_time', '>=', $currentTime);
                        })->orWhere(function ($sub) use ($currentTime) {
                            $sub->where('closing_time', '<', 'opening_time')
                                ->where(function ($inner) use ($currentTime) {
                                    $inner->where('opening_time', '<=', $currentTime)
                                        ->orWhere('closing_time', '>=', $currentTime);
                                });
                        });
                    });
            })
            ->pluck('id')
            ->toArray();

        // 🛑 If no restaurants are open, return empty response
        if (empty($openRestaurantIds)) {
            return response()->json([
                'total_size' => 0,
                'limit' => $limit,
                'offset' => $offset,
                'last_page' => 1,
                'products' => []
            ]);
        }

        $key = explode(' ', $request['name']);

        $sess_cart_id = $request->sess_cart_id ?? null;
        $user_id = $request->user_id ?? null;
        $temp_cart_items = collect();

        if ($sess_cart_id && $user_id) {
            $sess_cart_id = str_replace('%3A', ':', $sess_cart_id);
            $temp_cart = TempCart::where('sess_cart_id', $sess_cart_id)
                ->where('user_id', $user_id)
                ->where('status', 1)
                ->first();

            if ($temp_cart) {
                $temp_cart_items = TempCartItem::where('temp_cart_id', $temp_cart->id)
                    ->where('status', 1)
                    ->get()
                    ->keyBy('food_id');
            }
        }

        //->where('awt_buy_N_get_n_product_type','!=','applicable')
        $products = Food::active()->type($type)
            ->whereIn('restaurant_id', $openRestaurantIds)
            ->when($request->category_id, function ($query) use ($request) {
                $query->whereHas('category', function ($q) use ($request) {
                    return $q->whereId($request->category_id)
                        ->orWhere('parent_id', $request->category_id);
                });
            })
            ->when($request->restaurant_id, function ($query) use ($request) {
                return $query->where('restaurant_id', $request->restaurant_id);
            })
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
            })
            ->where('awt_buy_N_get_n_product_type', '!=', 'applicable')
            ->paginate($limit, ['*'], 'page', $offset);

        //   dd($products->items());exit;
        foreach ($products as $value) {
            // $value->image = url('/') . '/storage/app/public/product/' . $value->image;
            if ($value->image == '') {
                $value->image = '';
            }
        }
        $data = [
            'total_size' => $products->total(),
            'limit' => $limit,
            'offset' => $offset,
            'last_page' => $products->lastPage(),
            'products' => $products->items()
        ];

        $data['products'] = Helpers::product_data_formatting($products->items(), true, false, app()->getLocale());

        foreach ($data['products'] as &$product) {
            $product->quantity = isset($temp_cart_items[$product->id])
                ? $temp_cart_items[$product->id]->quantity
                : 0;
        }
        return response()->json($data, 200);
    }

    public function get_popular_products(Request $request)
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
        $products = ProductLogic::popular_products($zone_id, $request['limit'], $request['offset'], $type);
        $products['products'] = Helpers::product_data_formatting($products['products'], true, false, app()->getLocale());
        return response()->json($products, 200);
    }

    public function awt_get_popular_products(Request $request)
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
        $products = ProductLogic::awt_popular_products($zone_id, $request['limit'], $request['offset'], $type);
        $products['products'] = Helpers::product_data_formatting($products['products'], true, false, app()->getLocale());
        return response()->json($products, 200);
    }

    public function get_most_reviewed_products(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $req_Limit = 10;
        $req_offsete = 1;

        $type = $request->query('type', 'all');

        $zone_id = json_decode($request->header('zoneId'), true);

        $products = ProductLogic::most_reviewed_products($zone_id, $request['limit'], $request['offset'], $type);
        // $products = ProductLogic::most_reviewed_products($zone_id, $req_Limit, $req_offsete, $type);
        $products['products'] = Helpers::product_data_formatting($products['products'], true, false, app()->getLocale());
        //dd($products);exit;
        return response()->json($products, 200);
    }

    public function awt_get_most_reviewed_products(Request $request)
    {
        if (!$request->hasHeader('zoneId')) {
            $errors = [];
            array_push($errors, ['code' => 'zoneId', 'message' => translate('messages.zone_id_required')]);
            return response()->json([
                'errors' => $errors
            ], 403);
        }

        $req_Limit = 10;
        $req_offsete = 1;

        $type = $request->query('type', 'all');

        $zone_id = json_decode($request->header('zoneId'), true);

        $products = ProductLogic::awt_most_reviewed_products($zone_id, $request['limit'], $request['offset'], $type);
        // $products = ProductLogic::most_reviewed_products($zone_id, $req_Limit, $req_offsete, $type);
        $products['products'] = Helpers::product_data_formatting($products['products'], true, false, app()->getLocale());
        //dd($products);exit;
        return response()->json($products, 200);
    }

    public function get_product($id)
    {

        try {
            $product = ProductLogic::get_product($id);
            $product = Helpers::product_data_formatting($product, false, false, app()->getLocale());
            return response()->json($product, 200);
        } catch (\Exception $e) {
            return response()->json([
                'errors' => ['code' => 'product-001', 'message' => translate('messages.not_found')]
            ], 404);
        }
    }

    public function get_related_products($id)
    {
        if (Food::find($id)) {
            $products = ProductLogic::get_related_products($id);
            $products = Helpers::product_data_formatting($products, true, false, app()->getLocale());
            return response()->json($products, 200);
        }
        return response()->json([
            'errors' => ['code' => 'product-001', 'message' => translate('messages.not_found')]
        ], 404);
    }

    public function get_set_menus()
    {
        try {
            $products = Helpers::product_data_formatting(Food::active()->with(['rating'])->where(['set_menu' => 1, 'status' => 1])->get(), true, false, app()->getLocale());
            return response()->json($products, 200);
        } catch (\Exception $e) {
            return response()->json([
                'errors' => ['code' => 'product-001', 'message' => 'Set menu not found!']
            ], 404);
        }
    }

    public function get_product_reviews($food_id)
    {
        $reviews = Review::with(['customer', 'food'])->where(['food_id' => $food_id])->active()->get();

        $storage = [];
        foreach ($reviews as $item) {
            $item['attachment'] = json_decode($item['attachment']);
            $item['food_name'] = null;
            if ($item->food) {
                $item['food_name'] = $item->food->name;
                if (count($item->food->translations) > 0) {
                    $translate = array_column($item->food->translations->toArray(), 'value', 'key');
                    $item['food_name'] = $translate['name'];
                }
            }

            unset($item['food']);
            array_push($storage, $item);
        }

        return response()->json($storage, 200);
    }

    public function get_product_rating($id)
    {
        try {
            $product = Food::find($id);
            $overallRating = ProductLogic::get_overall_rating($product->reviews);
            return response()->json(floatval($overallRating[0]), 200);
        } catch (\Exception $e) {
            return response()->json(['errors' => $e], 403);
        }
    }

    public function submit_product_review(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'food_id' => 'required',
            'order_id' => 'required',
            'comment' => 'required',
            'rating' => 'required|numeric|max:5',
        ]);



        $product = Food::find($request->food_id);
        if (isset($product) == false) {
            $validator->errors()->add('food_id', translate('messages.food_not_found'));
        }

        $multi_review = Review::where(['food_id' => $request->food_id, 'user_id' => $request->user()->id, 'order_id' => $request->order_id])->first();
        if (isset($multi_review)) {
            return response()->json([
                'errors' => [
                    ['code' => 'review', 'message' => translate('messages.already_submitted')]
                ]
            ], 403);
        } else {
            $review = new Review;
        }

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $image_array = [];
        if (!empty($request->file('attachment'))) {
            foreach ($request->file('attachment') as $image) {
                if ($image != null) {
                    if (!Storage::disk('public')->exists('review')) {
                        Storage::disk('public')->makeDirectory('review');
                    }
                    array_push($image_array, Storage::disk('public')->put('review', $image));
                }
            }
        }

        $review->user_id = $request->user()->id;
        $review->food_id = $request->food_id;
        $review->order_id = $request->order_id;
        $review->comment = $request->comment;
        $review->rating = $request->rating;
        $review->attachment = json_encode($image_array);
        $review->save();

        $awtSaveOrderRate = DB::table('orders')->where('id', $request->order_id)->update(array('awt_is_review_done' => 1));

        if ($product->restaurant) {
            $restaurant_rating = RestaurantLogic::update_restaurant_rating($product->restaurant->rating, (int) $request->rating);
            $product->restaurant->rating = $restaurant_rating;
            $product->restaurant->save();
        }

        $product->rating = ProductLogic::update_rating($product->rating, (int) $request->rating);
        $product->avg_rating = ProductLogic::get_avg_rating(json_decode($product->rating, true));
        $product->save();
        $product->increment('rating_count');

        return response()->json(['message' => translate('messages.review_submited_successfully')], 200);
    }

    public function addCart(Request $request)
    {
        // info('Cart API request data.......' . print_r($request->all(), true));
        $validator = Validator::make($request->all(), [
            'sess_cart_id' => 'required',
            'user_id' => 'required',
            'restaurant_id' => 'required',
            'restaurant_name' => 'required',
            'category_id' => 'required',
            'product_name' => 'required',
            'grand_total' => 'required',
            'quantity' => 'required',
            'restaurant_lat' => 'required',
            'restaurant_long' => 'required',
            'id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        // info('AddCart API request data.......' . print_r($request->all(), true));
        $check_cart = TempCart::where('sess_cart_id', $request->sess_cart_id)->first();
        $food = Food::findOrFail($request->food_id);

        if ($request->id != 0) {
            $cart_item = TempCartItem::where('temp_cart_id', $check_cart->id)
                ->where('id', $request->id)
                ->first();
            $addon_price = $cart_item->addon_price / $cart_item->quantity;
        } else {
            $addon_price = $request->addon_price;
        }

        if ($request->option_price == '' || $request->option_price == 0) {
            $grand_total = $food->price + $addon_price;
        } else {
            $grand_total = $request->grand_total;
        }

        if ($check_cart) {
            $from_restaurant = Restaurant::findOrFail($check_cart->restaurant_id);
            $to_restaurant = Restaurant::findOrFail($request->restaurant_id);

            if ($check_cart->restaurant_id != $request->restaurant_id) {
                if ($request->confirm == '') {
                    return response()->json([
                        'status' => 'invalid',
                        'message' => 'Your cart contains food item from ' . $from_restaurant->name . '. Do you want to discard the selection and add food items from ' . $to_restaurant->name . '?'
                    ]);
                } elseif ($request->confirm == 'yes') {
                    // Clear previous cart items
                    TempCartItem::where('temp_cart_id', $check_cart->id)->delete();
                    $check_cart->grand_total = 0;
                    $check_cart->quantity = 0;
                } else {
                    // If user does not confirm, do not proceed
                    return response()->json([
                        'status' => 'cancelled',
                        'message' => 'Operation cancelled by the user.'
                    ]);
                }
            }

            $check_cart->grand_total += $grand_total;
            $check_cart->quantity += $request->quantity;
            $check_cart->restaurant_lat = $request->restaurant_lat;
            $check_cart->restaurant_name = $request->restaurant_name;
            $check_cart->restaurant_long = $request->restaurant_long;
            $check_cart->restaurant_id = $request->restaurant_id;
            $check_cart->save();
            $cart_id = $check_cart->id;
        } else {
            $create_cart = new TempCart();
            $create_cart->sess_cart_id = $request->sess_cart_id;
            $create_cart->user_id = $request->user_id;
            $create_cart->restaurant_id = $request->restaurant_id;
            $create_cart->restaurant_name = $request->restaurant_name;
            $create_cart->grand_total = $grand_total;
            $create_cart->quantity = $request->quantity;
            $create_cart->restaurant_lat = $request->restaurant_lat;
            $create_cart->restaurant_long = $request->restaurant_long;
            $create_cart->save();
            $cart_id = $create_cart->id;
        }

        $check_items = TempCartItem::where('temp_cart_id', $cart_id)
            ->where('food_id', $request->food_id)
            ->where('category_id', $request->category_id)
            ->whereRaw('price / quantity = ?', [$grand_total])
            ->get();

        if ($request->id != 0) {
            // Here I'm taking the Item with Existing Id
            $check_item = TempCartItem::where('temp_cart_id', $cart_id)
                ->where('id', $request->id)
                ->first();
            // Checking the condition for set-up the Grand Total based on Option price
            if ($request->option_price == '' || $request->option_price == 0) {
                // Here add on price is a total add-on price so dividing with the quantity
                info('food price is .........' . $food->price);
                $grand_total = $food->price + ($check_item->addon_price / $check_item->quantity);
            } else {
                info('grand_total price is .........' . $request->grand_total);
                $grand_total = $request->grand_total;
            }
            // Update existing item
            $check_item->price += $grand_total;
            $check_item->addon_price += ($check_item->addon_price / $check_item->quantity);
            $check_item->option_price += $check_item->option_price / $check_item->quantity ?? 0;
            $check_item->option_qtys = (int) $check_item->option_qtys + 1;
            $check_item->quantity += 1;
            $check_item->save();
        } else {
            // Flag to check if any matching item is found
            $matchingItemFound = false;
            if ($check_items->isNotEmpty()) {
                foreach ($check_items as $check_item) {
                    if ($check_item) {
                        $existingAddons = $check_item->addons_id ? json_decode($check_item->addons_id, true) : [];
                        $incomingAddons = json_decode($request->addons_id, true);

                        // Normalize existingAddons and incomingAddons arrays
                        $normalizedExistingAddons = array_map(function ($item) {
                            ksort($item);
                            return $item;
                        }, $existingAddons);

                        $normalizedIncomingAddons = array_map(function ($item) {
                            ksort($item);
                            return $item;
                        }, $incomingAddons);

                        // Check if the JSON representation of normalized arrays are exactly the same
                        if (json_encode($normalizedExistingAddons) === json_encode($normalizedIncomingAddons)) {
                            // Update existing item
                            $check_item->price += $grand_total;
                            $check_item->addon_price = $request->addon_price * ($check_item->quantity + 1);
                            $check_item->option_price = $request->option_price * ($check_item->quantity + 1);
                            $check_item->quantity += 1;
                            // $check_item->options_id = json_decode($request->options_id);
                            $check_item->save();

                            $delete_old = TempCartItem::where('id', $request->id)->delete();
                            // Set flag to true as matching item is found
                            $matchingItemFound = true;
                        }
                    }
                }
            }
            // If no matching item is found, create a new one
            if (!$matchingItemFound) {
                // Create a new item
                $temp_cart_item = new TempCartItem();
                $temp_cart_item->temp_cart_id = $cart_id;
                $temp_cart_item->food_id = $request->food_id;
                $temp_cart_item->category_id = $request->category_id;
                $temp_cart_item->product_name = $request->product_name;
                $temp_cart_item->addons_id = $request->addons_id;
                $temp_cart_item->price = $grand_total;
                $temp_cart_item->quantity = 1;
                $temp_cart_item->options_id = $request->options_id ?? null;
                $temp_cart_item->add_ons = $request->add_ons ?? '[]';
                $temp_cart_item->add_on_qtys = $request->add_on_qtys ?? '[]';
                $temp_cart_item->addon_price = $request->addon_price ?? 0;
                $temp_cart_item->option_price = $request->option_price ?? 0;
                $temp_cart_item->option_qtys = $request->option_qtys ?? null;
                $temp_cart_item->options = $request->options ?? null;
                $temp_cart_item->save();
            }
        }

        $temp_cart = TempCart::findOrFail($cart_id);

        $tax_value = 0;
        $items_overall_price = 0;
        if ($temp_cart) {
            $items = TempCartItem::where('temp_cart_id', $temp_cart->id)
                ->where('status', 1)
                ->get();

            foreach ($items as $val) {
                $val->addons_id = json_decode($val->addons_id, true);
                $val->options_id = $val->options_id ? json_decode($val->options_id, true) : null;
                $food = Food::findOrFail($val->food_id);

                $base_price = $val->option_price != 0 ? $val->option_price : $food->price;
                $discount_amount = $food->discount_type == 'percent' ? ($food->discount * $base_price / 100) : $food->discount;
                $discounted_price = $val->price - $discount_amount * $val->quantity;
                $individual_tax = Helpers::tax_calculate($food, $discounted_price);

                $val->tax = $individual_tax;
                $val->save();

                $tax_value += $individual_tax;
                $items_overall_price += $discounted_price;
            }

            $temp_cart->tax_value = (string) round($tax_value);
            $temp_cart->items_overall_price = (string) round($items_overall_price);
            $temp_cart->items = $items;
        }

        $responseData = array(
            'status' => 'valid',
            'message' => 'Added Successfully',
            'data' => $temp_cart
        );
        return \Response::json($responseData);
    }


    public function cart_details(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sess_cart_id' => 'required',
            'user_id' => 'required'
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $request->merge([
            'sess_cart_id' => str_replace('%3A', ':', $request->sess_cart_id)
        ]);

        $temp_cart = TempCart::where('sess_cart_id', $request->sess_cart_id)
            ->where('user_id', $request->user_id)
            ->where('status', 1)
            ->first();

        $coupon_tax_value = 0;
        $tax_value = 0;
        $discount = 0;
        $items_overall_price = 0;
        $total_coupon_discount = 0;
        $total_package_charge = 0;
        $package_charge = 0;

        if ($temp_cart) {
            $items = TempCartItem::where('temp_cart_id', $temp_cart->id)
                ->where('status', 1)
                ->get();

            $total_item_quantity = 0;

            foreach ($items as $val) {
                $val->addons_id = json_decode($val->addons_id, true);
                $val->options_id = json_decode($val->options_id, true);
                $food = Food::findOrFail($val->food_id);

                $base_price = $val->option_price != 0 ? $val->option_price / $val->quantity : $food->price;
                $discount_amount = $food->discount_type == 'percent' ? ($food->discount * $base_price / 100) : $food->discount;
                $discounted_price = $val->price - $discount_amount * $val->quantity;
                $tax_value += Helpers::tax_calculate($food, $discounted_price);

                $val->variant = $val->variant ?? '';
                $val->options = $val->options ?? '';
                $val->option_qtys = $val->option_qtys ?? '';
                $discount += $discount_amount * $val->quantity;
                $items_overall_price += $discounted_price;
                $val->price = (string) round($val->price);
                $val->discount_amount = $discount_amount * $val->quantity;
                $val->final_price = (string) round($discounted_price);

                $total_item_quantity += $val->quantity;
            }

            // Fetch package charge per item from restaurants table
            $package_charge = (float) Restaurant::where('id', $temp_cart->restaurant_id)
                ->value('awt_item_pckg_charge');

            $total_package_charge = $package_charge * $total_item_quantity;

            $temp_cart->tax_value = (string) round($tax_value);
            $temp_cart->total_coupon_discount = "";
            $temp_cart->coupon_after_price = "";
            $temp_cart->discount_message = "";

            // Handle coupon logic
            if ($request->coupon_code) {
                try {
                    info('Inside coupon of cart: ' . $request->coupon_code);
                    $restaurant_id = $temp_cart->restaurant_id;
                    $item_total = $items_overall_price;
                    $restaurant_discount = $discount;
                    $zone_id = Restaurant::where('id', $restaurant_id)->value('zone_id');

                    $coupon = Coupon::active()
                        ->where('code', $request->coupon_code)
                        ->whereJsonContains('data', (string) $restaurant_id)
                        ->first();

                    if (!$coupon) {
                        $coupons = Coupon::active()
                            ->where('code', $request->coupon_code)
                            ->get();

                        $matchingCoupons = $coupons->filter(function ($coupon) use ($zone_id) {
                            $data = json_decode($coupon->data, true);
                            return in_array($zone_id, $data);
                        });

                        $coupon = $matchingCoupons->first();
                        info('Matching coupon found: ' . ($coupon ? $coupon->code : 'none'));
                    }

                    if ($coupon) {
                        $item_total_after_discount = $item_total - $restaurant_discount;
                        $total_discount = min(
                            $item_total_after_discount * $coupon->discount / 100,
                            $coupon->max_discount
                        );
                        $coupon_after_price = max($item_total - $total_discount, 0);
                        $coupon_tax_value = Helpers::tax_calculate($food, $coupon_after_price);

                        $temp_cart->total_coupon_discount = (string) round($total_discount, 0);
                        $temp_cart->coupon_after_price = (string) round($coupon_after_price, 0);
                        $temp_cart->tax_value = (string) round($coupon_tax_value, 0);
                        $temp_cart->discount_message = "You saved ₹$total_discount with this coupon. Enjoy your savings!";
                        $total_coupon_discount = $total_discount;
                    } else {
                        $temp_cart->total_coupon_discount = '0';
                        $temp_cart->coupon_after_price = '0';
                        $temp_cart->tax_value = '0';
                        $temp_cart->discount_message = "No coupon applied.";
                    }
                } catch (\Exception $e) {
                    info('Error applying coupon: ' . $e->getMessage());
                    $temp_cart->total_coupon_discount = '0';
                    $temp_cart->coupon_after_price = '0';
                    $temp_cart->tax_value = '0';
                    $temp_cart->discount_message = "Error applying coupon.";
                }
            }

            $temp_cart->discount = (string) round($discount);
            $temp_cart->items_overall_price = (string) round($items_overall_price);
            $temp_cart->items = $items;
            $temp_cart->grand_total = (string) round($temp_cart->grand_total);
            $temp_cart->item_total = (string) round($temp_cart->grand_total);

            // Final total with packaging charges added
            $final_grand_total = $temp_cart->grand_total + $temp_cart->tax_value - $temp_cart->discount - round($total_coupon_discount) + $total_package_charge;

            // Assign packaging fields to response
            $temp_cart->package_charge_per_item = (string) round($package_charge, 2);
            $temp_cart->total_package_charge = (string) round($total_package_charge, 2);
            $temp_cart->final_grand_total = (string) round($final_grand_total);
        }

        // Final response
        if ($temp_cart) {
            $responseData = array(
                'status' => 'valid',
                'message' => 'Cart details retrieved successfully.',
                'data' => $temp_cart
            );
        } else {
            $responseData = array(
                'status' => 'invalid',
                'message' => 'Data not Found',
            );
        }

        return \Response::json($responseData);
    }


    public function updateCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sess_cart_id' => 'required',
            'user_id' => 'required',
            'restaurant_id' => 'required',
            'restaurant_name' => 'required',
            'category_id' => 'required',
            'product_name' => 'required',
            'grand_total' => 'required',
            'quantity' => 'required',
            'restaurant_lat' => 'required',
            'restaurant_long' => 'required',
            'id' => 'required'
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        // info('update cart API request from Front-end is ...............' . print_r($request->all(), true));
        $check_cart = TempCart::where('sess_cart_id', $request->sess_cart_id)->first();
        if ($check_cart) {
            // $check_cart->grand_total = $request->grand_total;
            // $check_cart->quantity = $request->quantity;
            // $check_cart->restaurant_lat = $request->restaurant_lat;
            // $check_cart->restaurant_long = $request->restaurant_long;
            // $check_cart->save();
            $cart_id = $check_cart->id;
        }

        if ($request->id != 0) {
            $check_items = TempCartItem::where('temp_cart_id', $cart_id)
                ->where('food_id', $request->food_id)
                ->where('category_id', $request->category_id)
                ->where('id', '!=', $request->id)
                ->get();
            // return $check_items;
            // Flag to check if any matching item is found
            $matchingItemFound = false;
            if ($check_items->isNotEmpty()) {
                foreach ($check_items as $check_item) {
                    if ($check_item) {
                        $existingAddons = $check_item->addons_id ? json_decode($check_item->addons_id, true) : [];
                        $incomingAddons = json_decode($request->addons_id, true);

                        // Normalize existingAddons and incomingAddons arrays
                        $normalizedExistingAddons = array_map(function ($item) {
                            ksort($item);
                            return $item;
                        }, $existingAddons);

                        $normalizedIncomingAddons = array_map(function ($item) {
                            ksort($item);
                            return $item;
                        }, $incomingAddons);

                        // Check if the JSON representation of normalized arrays are exactly the same
                        if (json_encode($normalizedExistingAddons) === json_encode($normalizedIncomingAddons)) {
                            // Update existing item
                            $check_item->price += $request->grand_total;
                            $check_item->addon_price = $request->addon_price * ($check_item->quantity + 1);
                            $check_item->option_price = $request->option_price * ($check_item->quantity + 1);
                            $check_item->quantity += 1;
                            // $check_item->options_id = json_decode($request->options_id);
                            $check_item->save();

                            $delete_old = TempCartItem::where('id', $request->id)->delete();
                            // Set flag to true as matching item is found
                            $matchingItemFound = true;
                        }
                    }
                }
            }

            // If no matching item is found, create a new one
            if (!$matchingItemFound) {
                // If the arrays are not exactly the same, proceed to create a new item
                // Create a new item
                $check_item = TempCartItem::where('temp_cart_id', $cart_id)
                    ->where('id', $request->id)
                    ->first();

                // Update existing item
                $check_item->price = $request->grand_total * $check_item->quantity;
                $check_item->addon_price = $request->addon_price * $check_item->quantity ?? 0;
                $check_item->option_price = $request->option_price * $check_item->quantity ?? 0;
                $check_item->option_qtys = $request->option_qtys ?? 0;
                $check_item->addons_id = $request->addons_id;
                $check_item->options_id = $request->options_id;
                $check_item->add_ons = $request->add_ons;
                $check_item->add_on_qtys = $request->add_on_qtys;
                $check_item->options = $request->options;
                $check_item->save();
            }
        }

        $temp_cart = TempCart::findOrFail($cart_id);
        $temp_cart->grand_total = TempCartItem::where('temp_cart_id', $cart_id)->sum('price');
        $temp_cart->quantity = TempCartItem::where('temp_cart_id', $cart_id)->sum('quantity');
        $temp_cart->save();

        $tax_value = 0;
        $items_overall_price = 0;
        if ($temp_cart) {
            $items = TempCartItem::where('temp_cart_id', $temp_cart->id)
                ->where('status', 1)
                ->get();

            foreach ($items as $val) {
                $val->addons_id = json_decode($val->addons_id, true);
                $val->options_id = $val->options_id ? json_decode($val->options_id, true) : null;
                $food = Food::findOrFail($val->food_id);

                $base_price = $val->option_price != 0 ? $val->option_price : $food->price;
                $discounted_price = $val->price - ($food->discount_type == 'percent' ? ($food->discount * $base_price / 100) : $food->discount * $val->quantity);
                $individual_tax = Helpers::tax_calculate($food, $discounted_price * ($val->option_price != 0 ? 1 : $val->quantity));

                $val->tax = $individual_tax;
                $val->save();

                $tax_value += $individual_tax;
                $items_overall_price += $discounted_price;
            }

            $temp_cart->tax_value = (string) round($tax_value);
            $temp_cart->items_overall_price = (string) round($items_overall_price);
            $temp_cart->items = $items;
        }

        $responseData = array(
            'status' => 'valid',
            'message' => 'Updated Successfully',
            'data' => $temp_cart
        );
        return \Response::json($responseData);
    }

    public function remove_cart_item(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sess_cart_id' => 'required',
            'user_id' => 'required',
            'food_id' => 'required',
            'category_id' => 'required',
            // 'price' => 'required',
            'id' => 'required'
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        // if (!$request->addons_id) {
        //     $request->addons_id = '[]';
        // }

        $temp_cart = TempCart::where('sess_cart_id', $request->sess_cart_id)
            ->where('user_id', $request->user_id)
            ->first();
        if (!$temp_cart) {
            return array(
                'status' => 'invalid',
                'message' => 'Cart not found'
            );
        }
        $delete_item = TempCartItem::where('temp_cart_id', $temp_cart->id)
            ->where('id', $request->id)
            // ->where('category_id', $request->category_id)
            ->first();
        // ->where('price', $request->price)
        // ->where('addons_id', $request->addons_id)
        $temp_cart->quantity -= 1;
        $temp_cart->grand_total -= $delete_item->price / $delete_item->quantity;
        $temp_cart->save();

        if ($delete_item->quantity == 1) {
            $delete_item->delete();
        } else {
            $delete_item->price -= $delete_item->price / $delete_item->quantity;
            $delete_item->addon_price -= $delete_item->addon_price / $delete_item->quantity;
            $delete_item->option_price -= $delete_item->option_price / $delete_item->quantity;
            $delete_item->quantity -= 1;
            $delete_item->option_qtys -= 1;
            // $delete_item->addon_price -= $delete_item->addon_price / $delete_item->quantity;

            $delete_item->save();
        }
        if ($temp_cart->quantity == 0) {
            $temp_cart->delete();
        }

        $tax_value = 0;
        $items_overall_price = 0;
        $discount = 0;
        if ($temp_cart) {
            $items = TempCartItem::where('temp_cart_id', $temp_cart->id)
                ->where('status', 1)
                ->get();
            foreach ($items as $val) {
                $val->addons_id = json_decode($val->addons_id, true);
                $val->options_id = json_decode($val->options_id, true);
                $food = Food::findOrFail($val->food_id);

                $base_price = $val->option_price != 0 ? $val->option_price / $val->quantity : $food->price;
                $discount_amount = $food->discount_type == 'percent' ? ($food->discount * $base_price / 100) : $food->discount;
                $discounted_price = $val->price - $discount_amount * $val->quantity;
                $tax = Helpers::tax_calculate($food, $discounted_price);
                $discount += $discount_amount * $val->quantity;
                $items_overall_price += $discounted_price;
                $tax_value += $tax;
                $val->tax = $tax;
                $val->save();
            }

            $temp_cart->tax_value = (string) round($tax_value);
            $temp_cart->items_overall_price = (string) $items_overall_price;
            $temp_cart->items = $items;
            $temp_cart->grand_total = (string) $temp_cart->grand_total;
        }

        $responseData = array(
            'status' => 'valid',
            'message' => 'Removed Successfully',
            'data' => $temp_cart
        );
        return \Response::json($responseData);
    }

    public function clear_cart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sess_cart_id' => 'required',
            'user_id' => 'required'
        ]);

        if ($validator->errors()->count() > 0) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $temp_cart = TempCart::where('sess_cart_id', $request->sess_cart_id)
            ->where('user_id', $request->user_id)
            ->first();
        if ($temp_cart) {
            $delete_item = TempCartItem::where('temp_cart_id', $temp_cart->id)
                ->delete();

            $temp_cart->delete();
        }

        $responseData = array(
            'status' => 'valid',
            'message' => 'Successful'
        );
        return \Response::json($responseData);
    }

    public function edit_item_data(Request $request)
    {
        // Fetch the product with given item_id
        $product = Food::active()->type('all')->where('id', $request->item_id)->first();
        $trans = false;
        $local = app()->getLocale() ?? 'en';

        // Check if the product exists
        if ($product) {
            $variations = [];

            // Map and format product fields
            if ($product->title) {
                $product['name'] = $product->title;
                unset($product['title']);
            }
            if ($product->start_time) {
                $product['available_time_starts'] = $product->start_time->format('H:i');
                unset($product['start_time']);
            }
            if ($product->end_time) {
                $product['available_time_ends'] = $product->end_time->format('H:i');
                unset($product['end_time']);
            }
            if ($product->start_date) {
                $product['available_date_starts'] = $product->start_date->format('Y-m-d');
                unset($product['start_date']);
            }
            if ($product->end_date) {
                $product['available_date_ends'] = $product->end_date->format('Y-m-d');
                unset($product['end_date']);
            }

            // Map and format categories
            $categories = [];
            foreach (json_decode($product['category_ids']) as $value) {
                $categories[] = ['id' => (string) $value->id, 'position' => $value->position];
            }
            $product['category_ids'] = $categories;

            // Decode JSON fields
            $product['attributes'] = json_decode($product['attributes']);
            $product['choice_options'] = json_decode($product['choice_options']);

            // Format add-ons and options
            $product['petpooja_add_ons'] = Helpers::petpooja_addon_data_formatting(
                $product['petpooja_add_ons'],
                $trans,
                $local,
                $product->veg ?? null
            );
            $product['add_ons'] = Helpers::addon_data_formatting(
                AddOn::withoutGlobalScope('translate')->whereIn('id', json_decode($product['add_ons']))->active()->get(),
                true,
                $trans,
                $local
            );
            $product['options'] = Helpers::option_data_formatting(
                Option::withoutGlobalScope('translate')->whereIn('id', json_decode($product['options']))->active()->get(),
                true,
                $trans,
                $local
            );

            // Format variations
            foreach (json_decode($product['variations'], true) as $var) {
                $variations[] = [
                    'type' => $var['type'],
                    'price' => (float) $var['price']
                ];
            }
            $product['variations'] = $variations;

            // Map restaurant data
            $product['restaurant_name'] = $product->restaurant->name;
            $product['restaurant_discount'] = Helpers::get_restaurant_discount($product->restaurant) ? $product->restaurant->discount->discount : 0;
            $product['restaurant_opening_time'] = $product->restaurant->opening_time ? $product->restaurant->opening_time->format('H:i') : null;
            $product['restaurant_closing_time'] = $product->restaurant->closeing_time ? $product->restaurant->closeing_time->format('H:i') : null;
            $product['restaurant_minimum_order'] = $product->restaurant->minimum_order;
            $product['restaurant_awt_item_pckg_charge'] = $product->restaurant->awt_product_pckg_charge;
            $product['restaurant_latitude'] = $product->restaurant->latitude;
            $product['restaurant_longitude'] = $product->restaurant->longitude;
            $product['schedule_order'] = $product->restaurant->schedule_order;
            $product['tax'] = $product->restaurant->tax;

            // Calculate ratings
            $product['rating_count'] = (int) ($product->rating ? array_sum(json_decode($product->rating, true)) : 0);
            $awt_rating_avg = (float) ($product->avg_rating ? $product->avg_rating : 0);
            $product['avg_rating'] = number_format($awt_rating_avg, 1);

            // Handle translations
            if ($trans) {
                $product['translations'][] = [
                    'translationable_type' => 'App\Models\Food',
                    'translationable_id' => $product->id,
                    'locale' => 'en',
                    'key' => 'name',
                    'value' => $product->name
                ];

                $product['translations'][] = [
                    'translationable_type' => 'App\Models\Food',
                    'translationable_id' => $product->id,
                    'locale' => 'en',
                    'key' => 'description',
                    'value' => $product->description
                ];
            }

            if (isset($product['translations']) && count($product['translations']) > 0) {
                foreach ($product['translations'] as $translation) {
                    if ($translation['locale'] == $local) {
                        if ($translation['key'] == 'name' || $translation['key'] == 'title') {
                            $product['name'] = $translation['value'];
                        }
                        if ($translation['key'] == 'description') {
                            $product['description'] = $translation['value'];
                        }
                    }
                }
            }

            if (!$trans) {
                unset($product['translations']);
            }

            // Remove unwanted fields
            unset($product['restaurant']);
            unset($product['rating']);
        }

        // Prepare response data
        $responseData = [
            'status' => 'valid',
            'message' => 'Successful',
            'data' => $product // changed from $final_product to $storage
        ];

        // Return response
        return response()->json($responseData);
    }

    public function re_order(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sess_cart_id' => 'required',
            'order_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        // info('re order API is called......' . print_r($request->all(), true));
        $order_details = Order::find($request->order_id);

        if (!$order_details) {
            return response()->json(['status' => 'invalid', 'message' => 'Order not Found']);
        }

        $order_items = OrderDetail::where('order_id', $request->order_id)->get();
        $create_cart = TempCart::firstOrNew(['sess_cart_id' => $request->sess_cart_id]);
        $to_restaurant = Restaurant::findOrFail($order_details->restaurant_id);

        if ($create_cart->exists && $create_cart->restaurant_id != $order_details->restaurant_id) {
            $from_restaurant = Restaurant::findOrFail($create_cart->restaurant_id);
            return response()->json([
                'status' => 'invalid',
                'message' => "Please clear the Cart to proceed. There are some items in the cart from {$from_restaurant->name}"
            ]);
        }

        $create_cart->fill([
            'user_id' => $order_details->user_id,
            'restaurant_id' => $order_details->restaurant_id,
            'restaurant_name' => $to_restaurant->name,
            'grand_total' => $order_details->order_amount + $order_details->restaurant_discount_amount - $order_details->total_tax_amount - $order_details->delivery_charge,
            'quantity' => 1,
            'restaurant_lat' => $to_restaurant->latitude,
            'restaurant_long' => $to_restaurant->longitude,
            'status' => 1
        ])->save();

        $create_cart_id = $create_cart->id;
        TempCartItem::where('temp_cart_id', $create_cart_id)->delete();
        $total_quantity = 0;
        $grand_total = 0;

        foreach ($order_items as $order_item) {
            $food = Food::find($order_item->food_id);
            if (!$food) {
                return response()->json(['status' => 'invalid', 'message' => 'Some Food is not available']);
            }

            // Process add-ons and add-on groups
            $add_ons = json_decode($order_item->add_ons);
            $food_details = json_decode($order_item->food_details);
            $petpooja_add_ons = isset($food_details->petpooja_add_ons) ? json_decode($food_details->petpooja_add_ons, true) : [];
            $grouped_add_ons = [];

            foreach ($add_ons as $add_on) {
                $add_on_model = AddOn::find($add_on->id);
                if ($add_on_model) {
                    $group_id = $add_on_model->addon_group_id;
                    $add_on_group = collect($petpooja_add_ons)->firstWhere('addon_group_id', $group_id);

                    if (!isset($grouped_add_ons[$group_id])) {
                        $grouped_add_ons[$group_id] = [
                            'addon_group_id' => $group_id,
                            'add_on_group_name' => AddonGroup::find($add_on_group['addon_group_id'])->value('group_name') ?? null,
                            'min_selection' => $add_on_group['addon_item_selection_min'] ?? null,
                            'max_selection' => $add_on_group['addon_item_selection_max'] ?? null,
                            'add_ons' => []
                        ];
                    }
                    $grouped_add_ons[$group_id]['add_ons'][] = [
                        'add_on_id' => $add_on->id,
                        'add_on_name' => $add_on->name,
                        'price' => $add_on->price,
                        'veg' => $add_on_model->veg,
                        'rank' => $add_on_model->rank,
                        'status' => $add_on_model->status
                    ];
                }
            }

            $add_ons_json = json_encode(array_values($grouped_add_ons));
            $options = json_decode($order_item->options);

            TempCartItem::create([
                'temp_cart_id' => $create_cart_id,
                'food_id' => $food->id,
                'category_id' => $food->category_id,
                'product_name' => $food->name,
                'addons_id' => $add_ons_json,
                'price' => $order_item->price * $order_item->quantity,
                'quantity' => $order_item->quantity,
                'variations' => $food->variations,
                'variant' => $food->variant,
                'options_id' => $order_item->options != '[]' ? $order_item->options : null,
                'add_ons' => json_encode(array_column($add_ons, 'id')) ?? [],
                'add_on_qtys' => json_encode(array_column($add_ons, 'quantity')) ?? [],
                'options' => $options->id ?? null,
                'option_qtys' => $options->quantity ?? 0,
                'addon_price' => (float) array_sum(array_column($add_ons, 'price')) ?? 0,
                'option_price' => $options->price ?? 0,
                'status' => 1,
                'tax' => $order_item->tax_amount
            ]);

            $total_quantity += $order_item->quantity;
            $grand_total += $order_item->price * $order_item->quantity;
        }

        $create_cart->update(['quantity' => $total_quantity, 'grand_total' => $grand_total]);

        $temp_cart = TempCart::where('sess_cart_id', $request->sess_cart_id)->where('status', 1)->first();
        if ($temp_cart) {
            $items = TempCartItem::where('temp_cart_id', $temp_cart->id)->where('status', 1)->get();
            $tax_value = 0;

            foreach ($items as $item) {
                $item->addons_id = json_decode($item->addons_id, true);
                $item->options_id = json_decode($item->options_id, true);
                $food = Food::findOrFail($item->food_id);
                $tax_value += Helpers::tax_calculate($food, $item->price);
                $item->variant = $item->variant ?? '';
                $item->options = $item->options ?? '';
                $item->option_qtys = $item->option_qtys ?? '';
            }

            $temp_cart->update(['tax_value' => round($tax_value), 'items' => $items]);
            $responseData = ['status' => 'valid', 'message' => 'Successful'];
        } else {
            $responseData = ['status' => 'invalid', 'message' => 'Data not Found'];
        }

        return response()->json($responseData);
    }

    public function test()
    {
        $test = Helpers::petPoojaSaveOrder(103903);
        return $test;
    }
}
