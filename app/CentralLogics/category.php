<?php

namespace App\CentralLogics;

use App\Models\Category;
use App\Models\Food;
use App\Models\Restaurant;
use Carbon\Carbon;

class CategoryLogic
{
    public static function parents()
    {
        return Category::where('position', 0)->get();
    }

    public static function child($parent_id)
    {
        return Category::where(['parent_id' => $parent_id])->get();
    }

    public static function awt_products(int $category_id, array $zone_id, int $limit, int $offset, $type)
    {
        $paginator = Food::whereHas('restaurant', function ($query) use ($zone_id) {
            return $query->whereIn('zone_id', $zone_id);
        })
            ->whereHas('category', function ($q) use ($category_id) {
                return $q->whereId($category_id)->orWhere('parent_id', $category_id);
            })
            ->active()->type($type)->where('awt_buy_N_get_n_product_type', '!=', 'applicable')->latest()->paginate($limit, ['*'], 'page', $offset);

        return [
            'total_size' => $paginator->total(),
            'limit' => $limit,
            'offset' => $offset,
            'products' => $paginator->items()
        ];
    }

    public static function products(int $category_id, array $zone_id, int $limit, int $offset, $type)
    {
        $paginator = Food::whereHas('restaurant', function ($query) use ($zone_id) {
            return $query->whereIn('zone_id', $zone_id);
        })
            ->whereHas('category', function ($q) use ($category_id) {
                return $q->whereId($category_id)->orWhere('parent_id', $category_id);
            })
            ->active()->type($type)->latest()->paginate($limit, ['*'], 'page', $offset);

        return [
            'total_size' => $paginator->total(),
            'limit' => $limit,
            'offset' => $offset,
            'products' => $paginator->items()
        ];
    }


    public static function restaurants(int $category_id, array $zone_id, int $limit, int $offset, $type, $scheduled_day_no = null)
    {
        // Step 1: Get the category name of this ID
        $category = Category::find($category_id);
        if (!$category) {
            return [
                'total_size' => 0,
                'limit' => $limit,
                'offset' => $offset,
                'restaurants' => []
            ];
        }

        // Step 2: Find all category IDs with the same name
        $category_ids = Category::where('name', $category->name)->pluck('id')->toArray();

        $paginator = Restaurant::with([
            'schedules' => function ($query) use ($scheduled_day_no) {
                if (isset($scheduled_day_no) && !empty($scheduled_day_no)) {
                    $query->where("day", $scheduled_day_no);
                }
            }
        ])->withOpen()->whereIn('zone_id', $zone_id)
            ->whereHas('foods.category', function ($query) use ($category_ids) {
                return $query->whereIn('id', $category_ids)->orWhereIn('parent_id', $category_ids);
            })

            // ->whereHas('category',function($q)use($category_id){
            //     return $q->whereId($category_id)->orWhere('parent_id', $category_id);
            // })
            ->active()->type($type)->orderBy('open', 'desc')->latest()->paginate($limit, ['*'], 'page', $offset);



        $awtData = $paginator->items();
        $storage = [];
        $awtSrno = 0;
        foreach ($awtData as $aD) {
            $awtSrno++;
            $aD['awt_category_index'] = $awtSrno;
            $aD['awt_category_id'] = $category_id;
            if ($aD->offer_start_date <= Carbon::now()->format('Y-m-d') && Carbon::now()->format('Y-m-d') <= $aD->offer_end_date) {
                // $aD->awt_offer_string = $value->awt_offer_string;
            } else {
                $aD->awt_offer_string = 'n/a';
            }
            array_push($storage, $aD);
        }
        $awtDataArr = $storage;
        //   dd($awtDataArr);
        //  exit;
        return [
            'total_size' => $paginator->total(),
            'limit' => $limit,
            'offset' => $offset,
            'restaurants' => $awtDataArr
            // 'restaurants' => $paginator->items()
        ];
    }


    public static function all_products($id)
    {
        $cate_ids = [];
        array_push($cate_ids, (int) $id);
        foreach (CategoryLogic::child($id) as $ch1) {
            array_push($cate_ids, $ch1['id']);
            foreach (CategoryLogic::child($ch1['id']) as $ch2) {
                array_push($cate_ids, $ch2['id']);
            }
        }

        return Food::whereIn('category_id', $cate_ids)->get();
    }
}
