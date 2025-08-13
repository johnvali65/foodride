<?php

namespace App\CentralLogics;

use App\Models\Restaurant;
use App\Models\OrderTransaction;
use Carbon\Carbon;
use URL;

class RestaurantLogic
{
    public static function get_restaurants($zone_id, $filter, $limit = 10, $offset = 1, $type = 'all', $scheduled_day_no = null)
    {
        //$query->where("day", $scheduled_day_no)->where("opening_time",">",date("H:i"));
        $paginator = Restaurant::with([
            'schedules' => function ($query) use ($scheduled_day_no) {
                if (isset($scheduled_day_no) && !empty($scheduled_day_no)) {
                    $query->where("day", $scheduled_day_no)->where("opening_time", ">", date("H:i"));
                }
            }
        ])->
            withOpen()
            ->with([
                'discount' => function ($q) {
                    return $q->validate();
                }
            ])->whereIn('zone_id', $zone_id)
            ->when($filter == 'delivery', function ($q) {
                return $q->delivery();
            })
            ->when($filter == 'take_away', function ($q) {
                return $q->takeaway();
            })
            ->Active()
            ->type($type)
            ->orderBy('open', 'desc')
            ->orderBy('priority', 'ASC')
            //->orderBy('active', 'desc')
            // ->where('open','=', 1)
            // ->where('active','=', 1)
            ->paginate($limit, ['*'], 'page', $offset);
        /*$paginator->count();*/

        foreach ($paginator as $value) {
            if ($value->offer_start_date <= Carbon::now()->format('Y-m-d') && Carbon::now()->format('Y-m-d') <= $value->offer_end_date) {
                // $value->awt_offer_string = $value->awt_offer_string;
            } else {
                $value->awt_offer_string = 'n/a';
            }

            // if ($value->logo == '') {
            //     $value->logo = '';
            // } else {
            //     if ($value->is_petpooja_linked_store == 0) {
            //         $value->logo = URL::to('/') . '/storage/app/public/restaurant/' . $value->image;
            //     }
            // }
        }
        return [
            'total_size' => $paginator->total(),
            'limit' => $limit,
            'offset' => $offset,
            'restaurants' => $paginator->items()
        ];
    }

    public static function awt_get_nonactive_restaurants($zone_id, $filter, $limit = 10, $offset = 1, $type = 'all')
    {
        $paginator = Restaurant::
            withOpen()
            ->with([
                'discount' => function ($q) {
                    return $q->validate();
                }
            ])->whereIn('zone_id', $zone_id)
            ->when($filter == 'delivery', function ($q) {
                return $q->delivery();
            })
            ->when($filter == 'take_away', function ($q) {
                return $q->takeaway();
            })
            ->Active()
            ->type($type)
            //->orderBy('open', 'desc')
            //->orderBy('active', 'desc')
            ->where('active', '=', 0)
            ->paginate($limit, ['*'], 'page', $offset);
        /*$paginator->count();*/
        return [
            'total_size' => $paginator->total(),
            'limit' => $limit,
            'offset' => $offset,
            'restaurants' => $paginator->items()
        ];
    }

    public static function get_latest_restaurants($zone_id, $limit = 10, $offset = 1, $type = 'all', $scheduled_day_no = null)
    {
        $paginator = Restaurant::with([
            'schedules' => function ($query) use ($scheduled_day_no) {
                if (isset($scheduled_day_no) && !empty($scheduled_day_no)) {
                    $query->where("day", $scheduled_day_no)->where("opening_time", ">", date("H:i"));
                }
            }
        ])->withOpen()
            ->with([
                'discount' => function ($q) {
                    return $q->validate();
                }
            ])->whereIn('zone_id', $zone_id)
            ->Active()
            ->type($type)
            ->orderBy('open', 'desc')
            ->latest()
            ->limit(50)
            ->get();
        // ->paginate($limit, ['*'], 'page', $offset);

        foreach ($paginator as $value) {
            if ($value->offer_start_date <= Carbon::now()->format('Y-m-d') && Carbon::now()->format('Y-m-d') <= $value->offer_end_date) {
                // $value->awt_offer_string = $value->awt_offer_string;
            } else {
                $value->awt_offer_string = 'n/a';
            }

            // if ($value->logo == '') {
            //     $value->logo = '';
            // } else {
            //     if ($value->is_petpooja_linked_store == 0) {
            //         $value->logo = URL::to('/') . '/storage/app/public/restaurant/' . $value->image;
            //     }
            // }
        }

        /*$paginator->count();*/
        // dd($paginator);exit;
        return [
            'total_size' => $paginator->count(),
            'limit' => $limit,
            'offset' => $offset,
            'restaurants' => $paginator
        ];
    }

    public static function get_popular_restaurants($zone_id, $limit = 10, $offset = 1, $type = 'all', $scheduled_day_no = null)
    {
        $paginator = Restaurant::with([
            'schedules' => function ($query) use ($scheduled_day_no) {
                if (isset($scheduled_day_no) && !empty($scheduled_day_no)) {
                    $query->where("day", $scheduled_day_no)->where("opening_time", ">", date("H:i"));
                }
            }
        ])->withOpen()
            ->with([
                'discount' => function ($q) {
                    return $q->validate();
                }
            ])->whereIn('zone_id', $zone_id)
            ->Active()
            ->type($type)
            ->withCount('orders')
            ->orderBy('open', 'desc')
            ->orderBy('orders_count', 'desc')
            ->limit(50)
            ->get();
        // ->paginate($limit, ['*'], 'page', $offset);

        foreach ($paginator as $value) {
            if ($value->offer_start_date <= Carbon::now()->format('Y-m-d') && Carbon::now()->format('Y-m-d') <= $value->offer_end_date) {
                // $value->awt_offer_string = $value->awt_offer_string;
            } else {
                $value->awt_offer_string = 'n/a';
            }

            // if ($value->logo == '') {
            //     $value->logo = '';
            // } else {
            //     if ($value->is_petpooja_linked_store == 0) {
            //         $value->logo = URL::to('/') . '/storage/app/public/restaurant/' . $value->logo;
            //     }
            // }
        }

        /*$paginator->count();*/
        return [
            'total_size' => $paginator->count(),
            'limit' => $limit,
            'offset' => $offset,
            'restaurants' => $paginator
        ];
    }

    public static function get_restaurant_details($restaurant_id)
    {
        return Restaurant::with([
            'discount' => function ($q) {
                return $q->validate();
            },
            'campaigns',
            'schedules'
        ])->active()->whereId($restaurant_id)->first();
    }

    public static function calculate_restaurant_rating($ratings)
    {
        $total_submit = $ratings[0] + $ratings[1] + $ratings[2] + $ratings[3] + $ratings[4];
        $rating = ($ratings[0] * 5 + $ratings[1] * 4 + $ratings[2] * 3 + $ratings[3] * 2 + $ratings[4]) / ($total_submit ? $total_submit : 1);
        return ['rating' => $rating, 'total' => $total_submit];
    }

    public static function update_restaurant_rating($ratings, $product_rating)
    {
        $restaurant_ratings = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        if ($ratings) {
            $restaurant_ratings[1] = $ratings[4];
            $restaurant_ratings[2] = $ratings[3];
            $restaurant_ratings[3] = $ratings[2];
            $restaurant_ratings[4] = $ratings[1];
            $restaurant_ratings[5] = $ratings[0];
            $restaurant_ratings[$product_rating] = $ratings[5 - $product_rating] + 1;
        } else {
            $restaurant_ratings[$product_rating] = 1;
        }
        return json_encode($restaurant_ratings);
    }

    // public static function search_restaurants($name, $zone_id, $category_id = null, $limit = 10, $offset = 1, $type = 'all', $scheduled_day_no = null)
    // {
    //     $key = explode(' ', $name);
    //     $paginator = Restaurant::with([
    //         'schedules' => function ($query) use ($scheduled_day_no) {
    //             // Nested query to fetch related schedules for restaurants
    //             if (isset($scheduled_day_no) && !empty($scheduled_day_no)) {
    //                 $query->where("day", $scheduled_day_no)->where("opening_time", ">", date("H:i"));
    //             }
    //         }
    //     ])
    //         ->withOpen() // Includes only open restaurants based on their schedules
    //         ->with([
    //             'discount' => function ($q) {
    //                 return $q->validate(); // Applies some validation to the discount relationship
    //             }
    //         ])
    //         ->whereIn('zone_id', $zone_id) // Filters restaurants by zone IDs
    //         ->weekday() // Applies a weekday scope to filter restaurants by their working days
    //         ->where(function ($q) use ($name) {
    //             // Uses a nested query to filter restaurants by their names
    //             $q->orWhere('name', 'like', '%' . $name . '%');
    //         })
    //         ->when($category_id, function ($query) use ($category_id) {
    //             // Applies a relationship query to filter restaurants by the given category_id
    //             $query->whereHas('foods.category', function ($q) use ($category_id) {
    //                 return $q->whereId($category_id)->orWhere('parent_id', $category_id);
    //             });
    //         })
    //         ->orWhere(function ($query) use ($name, $zone_id) {
    //             // Uses a nested query to filter restaurants by the food item name and zone_id
    //             $query->whereHas('foods', function ($q) use ($name) {
    //                 $q->where('name', 'like', '%' . $name . '%')
    //                 ->active();
    //             })->whereIn('zone_id', $zone_id);
    //         })
    //         ->active() // Applies a scope to filter only active restaurants
    //         ->type($type) // Applies a scope to filter restaurants by their type
    //         ->paginate($limit, ['*'], 'page', $offset); // Paginates the results

    //     foreach ($paginator as $value) {
    //         if ($value->offer_start_date <= Carbon::now()->format('Y-m-d') && Carbon::now()->format('Y-m-d') <= $value->offer_end_date) {
    //             // $value->awt_offer_string = $value->awt_offer_string;
    //         } else {
    //             $value->awt_offer_string = 'n/a';
    //         }

    //         // if ($value->logo == '') {
    //         //     $value->logo = '';
    //         // } else {
    //         //     if ($value->is_petpooja_linked_store == 0) {
    //         //         $value->logo = URL::to('/') . '/storage/app/public/restaurant/' . $value->image;
    //         //     }
    //         // }
    //     }

    //     // Return the paginated result as an array
    //     return [
    //         'total_size' => $paginator->total(),
    //         'limit' => $limit,
    //         'offset' => $offset,
    //         'restaurants' => $paginator->items()
    //     ];
    // }

    public static function search_restaurants($name, $zone_id, $category_id = null, $limit = 10, $offset = 1, $type = 'all', $scheduled_day_no = null)
    {
        $currentTime = date('H:i');

        $paginator = Restaurant::with([
            'schedules' => function ($query) use ($scheduled_day_no, $currentTime) {
                if (!is_null($scheduled_day_no)) {
                    $query->where('day', $scheduled_day_no)
                        ->where('opening_time', '<=', $currentTime)
                        ->where('closing_time', '>=', $currentTime);
                }
            },
            'discount' => function ($q) {
                return $q->validate();
            }
        ])
            ->whereIn('zone_id', $zone_id)
            ->whereHas('schedules', function ($query) use ($scheduled_day_no, $currentTime) {
                $query->where('day', $scheduled_day_no)
                    ->where('opening_time', '<=', $currentTime)
                    ->where('closing_time', '>=', $currentTime);
            })
            ->active()
            ->type($type)
            ->where(function ($q) use ($name, $zone_id, $category_id) {
                $q->where('name', 'like', '%' . $name . '%')
                    ->when($category_id, function ($query) use ($category_id) {
                        $query->whereHas('foods.category', function ($q) use ($category_id) {
                            $q->whereId($category_id)->orWhere('parent_id', $category_id);
                        });
                    })
                    ->orWhere(function ($query) use ($name, $zone_id) {
                        $query->whereHas('foods', function ($q) use ($name) {
                            $q->where('name', 'like', '%' . $name . '%')->active();
                        })->whereIn('zone_id', $zone_id);
                    });
            })
            ->paginate($limit, ['*'], 'page', $offset);

        foreach ($paginator as $value) {
            // Mark as open (since they're already filtered as open)
            $value->open = 1;

            // Offer check
            if (!($value->offer_start_date <= now()->format('Y-m-d') && now()->format('Y-m-d') <= $value->offer_end_date)) {
                $value->awt_offer_string = 'n/a';
            }
        }

        return [
            'total_size' => $paginator->total(),
            'limit' => $limit,
            'offset' => $offset,
            'restaurants' => $paginator->items()
        ];
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

    public static function get_earning_data($vendor_id)
    {
        $monthly_earning = OrderTransaction::whereMonth('created_at', date('m'))->NotRefunded()->where('vendor_id', $vendor_id)->sum('restaurant_amount');
        $weekly_earning = OrderTransaction::whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->NotRefunded()->where('vendor_id', $vendor_id)->sum('restaurant_amount');
        $daily_earning = OrderTransaction::whereDate('created_at', now())->NotRefunded()->where('vendor_id', $vendor_id)->sum('restaurant_amount');

        return ['monthely_earning' => (float) $monthly_earning, 'weekly_earning' => (float) $weekly_earning, 'daily_earning' => (float) $daily_earning];
    }

    public static function format_export_restaurants($restaurants)
    {
        $storage = [];
        foreach ($restaurants as $item) {
            if ($item->restaurants->count() < 1) {
                break;
            }
            $storage[] = [
                'id' => $item->id,
                'ownerFirstName' => $item->f_name,
                'ownerLastName' => $item->l_name,
                'restaurantName' => $item->restaurants[0]->name,
                // 'logo' => function ($item) {
                //     if ($item->restaurants[0]->logo == '') {
                //         return '';  // If the logo is empty, return an empty string
                //     } else {
                //         if ($item->restaurants[0]->is_petpooja_linked_store == 1) {
                //             return URL::to('/') . '/storage/app/public/restaurant/' . $item->restaurants[0]->image;
                //         }
                //     }
                // },
                'logo' => $item->restaurants[0]->logo,
                'phone' => $item->phone,
                'email' => $item->email,
                'latitude' => $item->restaurants[0]->latitude,
                'longitude' => $item->restaurants[0]->longitude,
                'zone_id' => $item->restaurants[0]->zone_id,
            ];
        }

        return $storage;
    }
}
