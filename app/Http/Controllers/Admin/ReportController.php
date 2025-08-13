<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTransaction;
use App\Models\Zone;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Scopes\RestaurantScope;
use Rap2hpoutre\FastExcel\FastExcel;

class ReportController extends Controller
{
    public function order_index()
    {
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }
        return view('admin-views.report.order-index');
    }

    public function day_wise_report(Request $request)
    {
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }

        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;

        $order_transactions=\App\Models\OrderTransaction::when(isset($zone), function($query)use($zone){
            return $query->whereIn('vendor_id', $zone->restaurants->pluck('vendor_id'));
        })->whereBetween('created_at', [$from, $to])->get();

        $order_transactions_list = \App\Models\OrderTransaction::when(isset($zone), function($query)use($zone){
            return $query->whereIn('vendor_id', $zone->restaurants->pluck('vendor_id'));
        })->whereBetween('created_at', [$from, $to])->latest()->paginate(25);

        return view('admin-views.report.day-wise-report', compact('zone','order_transactions','order_transactions_list'));
    }

    public function awt_delivery_rider_daily_report(Request $request){
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }

        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;

        $order_transactions=\App\Models\OrderTransaction::when(isset($zone), function($query)use($zone){
            return $query->whereIn('vendor_id', $zone->restaurants->pluck('vendor_id'));
        })->whereBetween('created_at', [$from, $to])->get();

        $order_transactions_list = \App\Models\OrderTransaction::when(isset($zone), function($query)use($zone){
            return $query->whereIn('vendor_id', $zone->restaurants->pluck('vendor_id'));
        })->whereBetween('created_at', [$from, $to])->latest()->paginate(25);

        $deliveryManIncomes = DB::table('awt_delivery_man_income')->orderBy('id', 'desc')->get();

        return view('admin-views.report.delivery-rider-daily-report', compact('zone','order_transactions','order_transactions_list', 'deliveryManIncomes'));
    }

    public function awt_day_wise_report(Request $request)
    {
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }

        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;

        $order_transactions=\App\Models\OrderTransaction::when(isset($zone), function($query)use($zone){
            return $query->whereIn('vendor_id', $zone->restaurants->pluck('vendor_id'));
        })->whereBetween('created_at', [$from, $to])->get();

        $order_transactions_list = \App\Models\OrderTransaction::when(isset($zone), function($query)use($zone){
            return $query->whereIn('vendor_id', $zone->restaurants->pluck('vendor_id'));
        })->whereBetween('created_at', [$from, $to])->latest()->paginate(25);

        return view('admin-views.report.awt-day-wise-report', compact('zone','order_transactions','order_transactions_list'));
    }

    public function food_wise_report(Request $request)
    {
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }
        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;
        $foods = \App\Models\Food::withoutGlobalScope(RestaurantScope::class)->withCount([
            'orders' => function($query)use($from, $to) {
                $query->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
            },
        ])
        ->when(isset($zone), function($query)use($zone){
            return $query->whereIn('restaurant_id', $zone->restaurants->pluck('id'));
        })
        ->when(isset($restaurant), function($query)use($restaurant){
            return $query->where('restaurant_id', $restaurant->id);
        })
        ->orderBy('orders_count', 'desc')
        ->paginate(config('default_pagination'))->withQueryString();
        return view('admin-views.report.food-wise-report', compact('zone', 'restaurant', 'foods'));
    }

    public function order_transaction()
    {
        $order_transactions = OrderTransaction::latest()->paginate(config('default_pagination'));
        return view('admin-views.report.order-transactions', compact('order_transactions'));
    }


    public function set_date(Request $request)
    {
        session()->put('from_date', date('Y-m-d', strtotime($request['from'])));
        session()->put('to_date', date('Y-m-d', strtotime($request['to'])));
        return back();
    }

    public function food_search(Request $request){
        $key = explode(' ', $request['search']);

        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;
        $foods = \App\Models\Food::withoutGlobalScope(RestaurantScope::class)->withCount([
            'orders as order_count' => function($query)use($from, $to) {
                $query->whereBetween('created_at', [$from, $to]);
            },
        ])
        ->when(isset($zone), function($query)use($zone){
            return $query->whereIn('restaurant_id', $zone->restaurants->pluck('id'));
        })
        ->when(isset($restaurant), function($query)use($restaurant){
            return $query->where('restaurant_id', $restaurant->id);
        })
        ->where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('name', 'like', "%{$value}%");
            }
        })
        ->limit(25)->get();

        return response()->json(['count'=>count($foods),
            'view'=>view('admin-views.report.partials._food_table',compact('foods'))->render()
        ]);
    }
    public function day_search(Request $request){
        $key = explode(' ', $request['search']);

        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;

        $ot = \App\Models\OrderTransaction::
        where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('order_id', 'like', "%{$value}%");
            }
        })
        ->first();

        return response()->json([
            'view'=>view('admin-views.report.partials._day_table',compact('ot'))->render()
        ]);
    }

    public function food_wise_report_export(Request $request){
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }
        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;
        $foods = \App\Models\Food::withoutGlobalScope(RestaurantScope::class)->withCount([
            'orders' => function($query)use($from, $to) {
                $query->whereBetween('created_at', [$from.' 00:00:00', $to.' 23:59:59']);
            },
        ])
        ->when(isset($zone), function($query)use($zone){
            return $query->whereIn('restaurant_id', $zone->restaurants->pluck('id'));
        })
        ->when(isset($restaurant), function($query)use($restaurant){
            return $query->where('restaurant_id', $restaurant->id);
        })
        ->latest()
        ->get();


        if($request->type == 'excel'){
            return (new FastExcel($foods))->download('FoodWiseDailyReport.xlsx');
        }elseif($request->type == 'csv'){
            return (new FastExcel($foods))->download('FoodWiseDailyReport.csv');
        }
    }

    public function day_wise_report_export(Request $request){
        if (session()->has('from_date') == false) {
            session()->put('from_date', date('Y-m-01'));
            session()->put('to_date', date('Y-m-30'));
        }

        $from = session('from_date');
        $to = session('to_date');

        $zone_id = $request->query('zone_id', isset(auth('admin')->user()->zone_id)?auth('admin')->user()->zone_id:'all');
        $restaurant_id = $request->query('restaurant_id', 'all');
        $zone = is_numeric($zone_id)?Zone::findOrFail($zone_id):null;
        $restaurant = is_numeric($restaurant_id)?Restaurant::findOrFail($restaurant_id):null;

        $order_transactions=\App\Models\OrderTransaction::when(isset($zone), function($query)use($zone){
            return $query->whereIn('vendor_id', $zone->restaurants->pluck('vendor_id'));
        })->whereBetween('created_at', [$from, $to])->get();


        if($request->type == 'excel'){
            return (new FastExcel($order_transactions))->download('DayWiseDailyReport.xlsx');
        }elseif($request->type == 'csv'){
            return (new FastExcel($order_transactions))->download('DayWiseDailyReport.csv');
        }
    }
}
