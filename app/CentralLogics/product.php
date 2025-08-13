<?php

namespace App\CentralLogics;

use App\Models\Category;
use App\Models\Food;
use App\Models\Review;
use App\Models\Restaurant;
use App\Models\RestaurantSchedule;
use Illuminate\Support\Facades\DB;
use URL;

class ProductLogic
{
    public static function get_product($id)
    {
        return Food::active()->where('id', $id)->first();
    }

    public static function awt_get_latest_products($limit, $offset, $restaurant_id, $category_id, $type = 'all')
    {
        $paginator = Food::active()->type($type)
            ->available(date('H:i:s'))
            ->whereHas('category', function ($q) {
                return $q->where('status', 1);  // Check if category status is 1
            });
        if ($category_id != 0) {
            $paginator = $paginator->whereHas('category', function ($q) use ($category_id) {
                return $q->whereId($category_id)->orWhere('parent_id', $category_id);
            });
        }
        $limit = 20;
        $paginator = $paginator->where('restaurant_id', $restaurant_id)->where('awt_buy_N_get_n_product_type', '!=', 'applicable')->latest()->paginate($limit, ['*'], 'page', $offset);
        foreach ($paginator as $val) {
            if ($val->image == '') {
                $val->image = '';
            } else {
                if ($val->petpooja_item_id == null) {
                    $val->image = URL::to('/') . '/storage/app/public/product/' . $val->image;
                }
            }
        }
        //   $paginator = $paginator->withCount('reviews')->where('restaurant_id', $restaurant_id)->orderBy('reviews_count','desc')->paginate($limit, ['*'], 'page', $offset);
        $awtResData = Restaurant::where('id', $restaurant_id)->select('id', 'name', 'latitude', 'longitude', 'awt_item_pckg_charge', 'awt_fassi')->get();
        return [
            'total_size' => $paginator->lastPage(),
            'limit' => $limit,
            'offset' => $offset,
            'awt_res_details' => $awtResData,
            'products' => $paginator->items()
        ];
    }

    public static function awt_get_grouped_latest_products($limit, $offset, $restaurant_id, $type = 'all', $veg = null, $category_id = null)
    {

        $categoryIdsQuery = Food::select('category_id')
            ->where('restaurant_id', $restaurant_id)
            ->where('awt_buy_N_get_n_product_type', '!=', 'applicable')
            ->when($veg !== null, fn($q) => $q->where('veg', $veg))
            ->when($type !== 'all', fn($q) => $q->where('type', $type));

        // If a specific category or its children is selected
        if (!is_null($category_id) && $category_id != 0) {
            $categoryIdsQuery->whereIn('category_id', function ($subQuery) use ($category_id) {
                $subQuery->select('id')
                    ->from('categories')
                    ->where('id', $category_id)
                    ->orWhere('parent_id', $category_id);
            });
        }

        // Get distinct category IDs
        $categoryIds = $categoryIdsQuery->distinct()->pluck('category_id')->toArray();

        $categoriesPaginated = Category::whereIn('id', $categoryIds)
            ->orderBy('priority')
            ->paginate($limit, ['*'], 'page', $offset);

        $categories = $categoriesPaginated->items();

        // Create lookup maps for category name and priority
        $categoryMap = Category::whereIn('id', $categoryIds)->pluck('name', 'id')->toArray();
        $categoryPriorityMap = Category::whereIn('id', $categoryIds)->pluck('priority', 'id')->toArray();

        $productsQuery = Food::active()
            ->available(date('H:i:s'))
            ->type($type)
            ->where('restaurant_id', $restaurant_id)
            ->where('awt_buy_N_get_n_product_type', '!=', 'applicable')
            ->whereIn('category_id', collect($categories)->pluck('id')->toArray())
            ->with(['restaurant', 'category', 'reviews']);

        if (!is_null($veg)) {
            $productsQuery->where('veg', $veg);
        }

        $products = $productsQuery->latest()->get();
        $formatted = Helpers::product_data_formatting($products, true, false, app()->getLocale());

        $grouped = [];

        foreach ($categories as $catObj) {
            $catId = $catObj->id;

            $grouped[$catId] = [
                'id' => $catId,
                'name' => $categoryMap[$catId] ?? 'Unknown',
                'priority' => $categoryPriorityMap[$catId] ?? 999,
                'products' => []
            ];
        }

        foreach ($formatted as $product) {
            foreach ($product['category_ids'] as $cat) {
                $originalCatId = (int) $cat['id'];

                $category = Category::find($originalCatId);

                // If it's a child category, use parent_id if position == 1
                $catId = ($category && $category->position == 1 && $category->parent_id)
                    ? (int) $category->parent_id
                    : $originalCatId;

                if (isset($grouped[$catId])) {
                    $grouped[$catId]['products'][] = $product;
                }
            }
        }

        $categoriesResponse = array_values(array_map(function ($cat) {
            return [
                'id' => $cat['id'],
                'name' => $cat['name'],
                'count' => (string) count($cat['products']),
                'products' => $cat['products']
            ];
        }, $grouped));

        $awtResData = Restaurant::where('id', $restaurant_id)
            ->select('id', 'name', 'latitude', 'longitude', 'awt_item_pckg_charge', 'awt_fassi')
            ->get();

        return [
            'total_size' => $categoriesPaginated->lastPage(),
            'limit' => (int) $limit,
            'offset' => $offset,
            'awt_res_details' => $awtResData,
            'categories' => $categoriesResponse
        ];
    }



    public static function get_latest_products($limit, $offset, $restaurant_id, $category_id, $type = 'all')
    {
        $paginator = Food::active()->type($type);
        if ($category_id != 0) {
            $paginator = $paginator->whereHas('category', function ($q) use ($category_id) {
                return $q->whereId($category_id)->orWhere('parent_id', $category_id);
            });
        }
        $paginator = $paginator->where('restaurant_id', $restaurant_id)->latest()->paginate($limit, ['*'], 'page', $offset);
        //   $paginator = $paginator->withCount('reviews')->where('restaurant_id', $restaurant_id)->orderBy('reviews_count','desc')->paginate($limit, ['*'], 'page', $offset);
        $awtResData = Restaurant::where('id', $restaurant_id)->select('id', 'name', 'latitude', 'longitude', 'awt_item_pckg_charge', 'awt_fassi')->get();
        return [
            'total_size' => $paginator->total(),
            'limit' => $limit,
            'offset' => $offset,
            'awt_res_details' => $awtResData,
            'products' => $paginator->items()
        ];
    }

    public static function get_related_products($product_id)
    {
        $product = Food::find($product_id);
        return Food::active()
            ->whereHas('restaurant', function ($query) {
                $query->Weekday();
            })
            ->where('category_ids', $product->category_ids)
            ->where('id', '!=', $product->id)
            ->limit(10)
            ->get();
    }

    public static function search_products($name, $zone_id, $limit = 10, $offset = 1)
    {
        $key = explode(' ', $name);
        $paginator = Food::active()->whereHas('restaurant', function ($q) use ($zone_id) {
            $q->whereIn('zone_id', $zone_id)->Weekday();
        })->where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('name', 'like', "%{$value}%");
            }
        })->paginate($limit, ['*'], 'page', $offset);

        //  $awtResData = Restaurant::where('id', 1)->select('id','name','latitude','longitude','awt_item_pckg_charge')->get();

        return [
            'total_size' => $paginator->lastPage(),
            'limit' => $limit,
            'offset' => $offset,
            'products' => $paginator->items()
        ];
    }

    public static function popular_products($zone_id, $limit = null, $offset = null, $type = 'all')
    {
        if ($limit != null && $offset != null) {
            $paginator = Food::whereHas('restaurant', function ($q) use ($zone_id) {
                $q->whereIn('zone_id', $zone_id)->Weekday();
            })->active()->type($type)->popular()->paginate($limit, ['*'], 'page', $offset);
            //  dd($paginator->items());exit;
            return [
                'total_size' => $paginator->total(),
                'limit' => $limit,
                'offset' => $offset,
                'products' => $paginator->items()
            ];
        }
        $paginator = Food::active()->type($type)->whereHas('restaurant', function ($q) use ($zone_id) {
            $q->whereIn('zone_id', $zone_id)->Weekday();
        })->popular()->limit(50)->get();

        // d1ump($type);
        // $arr = $paginator->toArray();
        //     dd(array_unique(array_column($arr, "restaurant_id")));

        return [
            'total_size' => $paginator->count(),
            'limit' => $limit,
            'offset' => $offset,
            'products' => $paginator
        ];

    }

    public static function awt_popular_products($zone_id, $limit = null, $offset = null, $type = 'all')
    {
        if ($limit != null && $offset != null) {
            $paginator = Food::whereHas('restaurant', function ($q) use ($zone_id) {
                $q->whereIn('zone_id', $zone_id)->weekday()->mustBeOpen();
            })->active()->type($type)->where('available_time_starts', '<=', date('H:i:s'))->where('available_time_ends', '>', date('H:i:s'))->where('awt_buy_N_get_n_product_type', '!=', 'applicable')->popular()->paginate($limit, ['*'], 'page', $offset);
            // dd($paginator->items());exit;
            return [
                'total_size' => $paginator->total(),
                'limit' => $limit,
                'offset' => $offset,
                'products' => $paginator->items()
            ];
        }
        $paginator = Food::active()->type($type)->whereHas('restaurant', function ($q) use ($zone_id) {
            $q->whereIn('zone_id', $zone_id)->weekday()->mustBeOpen();
        })->where('available_time_starts', '<=', date('H:i:s'))->where('available_time_ends', '>', date('H:i:s'))->where('awt_buy_N_get_n_product_type', '!=', 'applicable')->popular()->limit(50)->get();

        // d1ump($type);
        // $arr = $paginator->toArray();
        // dd(array_unique(array_column($arr, "restaurant_id")));

        return [
            'total_size' => $paginator->count(),
            'limit' => $limit,
            'offset' => $offset,
            'products' => $paginator
        ];

    }

    public static function awt_most_reviewed_products($zone_id, $limit = null, $offset = null, $type = 'all')
    {
        info('Limit and offset are not null', ['limit' => $limit, 'offset' => $offset, 'zone_id' => $zone_id]);
        $zone_id = is_array($zone_id) ? $zone_id : ($zone_id ? [$zone_id] : []);

        // 🧼 Bail out early if zone_id is empty
        if (empty($zone_id)) {
            info('zone_id is empty or invalid — returning empty response');

            return [
                'total_size' => 0,
                'limit' => $limit,
                'offset' => $offset,
                'awt_res_details' => [],
                'products' => []
            ];
        }

        if ($limit != null && $offset != null) {
            /*$paginator = Food::whereHas('restaurant', function($q)use($zone_id){
                $q->whereIn('zone_id', $zone_id)->Weekday();
            })->withCount('reviews')->active()->type($type)
            ->orderBy('reviews_count','desc')
            ->where('awt_buy_N_get_n_product_type','!=','applicable')
            ->paginate($limit, ['*'], 'page', $offset);*/
            // dd($paginator->items());exit;

            //dd($paginator->toSql);exit;

            //  dd($awtDayofweek);exit;


            $paginator = Food::whereHas('restaurant', function ($q) use ($zone_id) {
                $q->whereIn('zone_id', $zone_id)->weekday()->mustBeOpen();
            })->active()->type($type)
                ->orderBy('reviews_count', 'desc')
                ->where('available_time_starts', '<=', date('H:i:s'))
                ->where('available_time_ends', '>', date('H:i:s'))
                ->where('awt_buy_N_get_n_product_type', '!=', 'applicable')
                ->paginate($limit, ['*'], 'page', $offset);

            info('Paginator query executed', ['paginator' => $paginator->items()]);

            $awtResData = Restaurant::where('id', 1)->select('id', 'name', 'latitude', 'longitude', 'awt_item_pckg_charge')->get();

            return [
                'total_size' => $paginator->total(),
                'limit' => $limit,
                'offset' => $offset,
                'awt_res_details' => $awtResData,
                'products' => $paginator->items()
            ];
        }

        // info('Limit or offset is null');

        $paginator = Food::active()->where('add_ons', '=', '[]')->type($type)->whereHas('restaurant', function ($q) use ($zone_id) {
            $q->whereIn('zone_id', $zone_id)->weekday()->mustBeOpen();
        })
            // ->withCount('reviews')
            ->orderBy('reviews_count', 'desc')
            ->where('available_time_starts', '<=', date('H:i:s'))
            ->where('available_time_ends', '>', date('H:i:s'))
            ->where('awt_buy_N_get_n_product_type', '!=', 'applicable')
            ->limit(50)->get();

        info('Paginator query executed', ['paginator' => $paginator]);
        $awtResData = Restaurant::where('id', 1)->select('id', 'name', 'latitude', 'longitude', 'awt_item_pckg_charge')->get();
        return [
            'total_size' => $paginator->count(),
            'limit' => $limit,
            'offset' => $offset,
            'awt_res_details' => $awtResData,
            'products' => $paginator
        ];
    }

    public static function most_reviewed_products($zone_id, $limit = null, $offset = null, $type = 'all')
    {
        if ($limit != null && $offset != null) {
            $paginator = Food::whereHas('restaurant', function ($q) use ($zone_id) {
                $q->whereIn('zone_id', $zone_id)->Weekday();
            })->active()->type($type)
                ->orderBy('reviews_count', 'desc')
                ->paginate($limit, ['*'], 'page', $offset);

            $awtResData = Restaurant::where('id', 1)->select('id', 'name', 'latitude', 'longitude', 'awt_item_pckg_charge')->get();

            return [
                'total_size' => $paginator->total(),
                'limit' => $limit,
                'offset' => $offset,
                'awt_res_details' => $awtResData,
                'products' => $paginator->items()
            ];
        }
        $paginator = Food::active()->where('add_ons', '=', '[]')->type($type)->whereHas('restaurant', function ($q) use ($zone_id) {
            $q->whereIn('zone_id', $zone_id)->Weekday();
        })
            // ->withCount('reviews')
            ->orderBy('reviews_count', 'desc')
            ->limit(50)->get();

        $awtResData = Restaurant::where('id', 1)->select('id', 'name', 'latitude', 'longitude', 'awt_item_pckg_charge')->get();

        return [
            'total_size' => $paginator->count(),
            'limit' => $limit,
            'offset' => $offset,
            'awt_res_details' => $awtResData,
            'products' => $paginator
        ];

    }

    public static function get_product_review($id)
    {
        $reviews = Review::where('product_id', $id)->get();
        return $reviews;
    }

    public static function get_rating($reviews)
    {
        $rating5 = 0;
        $rating4 = 0;
        $rating3 = 0;
        $rating2 = 0;
        $rating1 = 0;
        foreach ($reviews as $key => $review) {
            if ($review->rating == 5) {
                $rating5 += 1;
            }
            if ($review->rating == 4) {
                $rating4 += 1;
            }
            if ($review->rating == 3) {
                $rating3 += 1;
            }
            if ($review->rating == 2) {
                $rating2 += 1;
            }
            if ($review->rating == 1) {
                $rating1 += 1;
            }
        }
        return [$rating5, $rating4, $rating3, $rating2, $rating1];
    }

    public static function get_avg_rating($rating)
    {
        $total_rating = 0;
        $total_rating += $rating[1];
        $total_rating += $rating[2] * 2;
        $total_rating += $rating[3] * 3;
        $total_rating += $rating[4] * 4;
        $total_rating += $rating[5] * 5;

        return $total_rating / array_sum($rating);
    }

    public static function get_overall_rating($reviews)
    {
        $totalRating = count($reviews);
        $rating = 0;
        foreach ($reviews as $key => $review) {
            $rating += $review->rating;
        }
        if ($totalRating == 0) {
            $overallRating = 0;
        } else {
            $overallRating = number_format($rating / $totalRating, 2);
        }

        return [$overallRating, $totalRating];
    }

    public static function format_export_foods($foods)
    {
        $storage = [];
        foreach ($foods as $item) {
            $category_id = 0;
            $sub_category_id = 0;
            foreach (json_decode($item->category_ids, true) as $category) {
                if ($category['position'] == 1) {
                    $category_id = $category['id'];
                } else if ($category['position'] == 2) {
                    $sub_category_id = $category['id'];
                }
            }
            $storage[] = [
                'id' => $item->id,
                'name' => $item->name,
                'description' => $item->description,
                'image' => $item->image,
                'veg' => $item->veg,
                'category_id' => $category_id,
                'sub_category_id' => $sub_category_id,
                'price' => $item->price,
                'discount' => $item->discount,
                'discount_type' => $item->discount_type,
                'available_time_starts' => $item->available_time_starts,
                'available_time_ends' => $item->available_time_ends,
                'variations' => str_replace(['{', '}', '[', ']'], ['(', ')', '', ''], $item->variations),
                'add_ons' => str_replace(['"', '[', ']'], '', $item->add_ons),
                'attributes' => str_replace(['"', '[', ']'], '', $item->attributes),
                'choice_options' => str_replace(['{', '}'], ['(', ')'], substr($item->choice_options, 1, -1)),
                'restaurant_id' => $item->restaurant_id,
            ];
        }

        return $storage;
    }

    public static function update_food_ratings()
    {
        try {
            $foods = Food::withOutGlobalScopes()->whereHas('reviews')->with('reviews')->get();
            foreach ($foods as $key => $food) {
                $foods[$key]->avg_rating = $food->reviews->avg('rating');
                $foods[$key]->rating_count = $food->reviews->count();
                foreach ($food->reviews as $review) {
                    $foods[$key]->rating = self::update_rating($foods[$key]->rating, $review->rating);
                }
                $foods[$key]->save();
            }
        } catch (\Exception $e) {
            info($e->getMessage());
            return false;
        }
        return true;
    }

    public static function update_rating($ratings, $product_rating)
    {

        $restaurant_ratings = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        if (isset($ratings)) {
            $restaurant_ratings = json_decode($ratings, true);
            $restaurant_ratings[$product_rating] = $restaurant_ratings[$product_rating] + 1;
        } else {
            $restaurant_ratings[$product_rating] = 1;
        }
        return json_encode($restaurant_ratings);
    }
}
