<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Restaurant;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    public function add_new()
    {
        $newPagination=200;
        //$coupons = Coupon::latest()->paginate(config('default_pagination'));
        $coupons = Coupon::latest()->paginate($newPagination);
        $restaurant = Restaurant::get();
       // dd($restaurantData);exit;
        return view('admin-views.coupon.index', compact('coupons','restaurant'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'code' => 'required|unique:coupons|max:100',
            'title' => 'required|max:191',
            'start_date' => 'required',
            'expire_date' => 'required',
            'discount' => 'required',
            'display_discount' => 'required',
            'coupon_type' => 'required|in:zone_wise,restaurant_wise,free_delivery,first_order,default',
            'zone_ids' => 'required_if:coupon_type,zone_wise',
            'restaurant_ids' => 'required_if:coupon_type,restaurant_wise'
        ]);
        $data  = '';
        if($request->coupon_type == 'zone_wise')
        {
            $data = $request->zone_ids;
            $awtIsHightligted = $request->is_highlighted;
            if($awtIsHightligted == "yes"){
                $awtCouponStr = $request->title;
            }else if($awtIsHightligted == "no"){
                $awtCouponStr = "n/a";
            }else{
                $awtCouponStr = "n/a";
            }
            for($a=0;$a<sizeof($data);$a++){
                $awtZoneId=$data[$a];
                $restaurants = DB::table('restaurants')->where('zone_id', $awtZoneId)->get();
                foreach($restaurants as $val){
                    DB::table('restaurants')->where(['id' => $val->id])->update([
                        'awt_offer_string' => $awtCouponStr,
                        'offer_start_date' => $request->start_date,
                        'offer_end_date' => $request->expire_date
                    ]);
                }
            }
            DB::table('coupons')->insert([
                'title' => $request->title,
                'code' => $request->code,
                'limit' => $request->coupon_type=='first_order'?1:$request->limit,
                'coupon_type' => $request->coupon_type,
                'start_date' => $request->start_date,
                'expire_date' => $request->expire_date,
                'min_purchase' => $request->min_purchase != null ? $request->min_purchase : 0,
                'max_discount' => $request->max_discount != null ? $request->max_discount : 0,
                'discount' => $request->discount_type == 'amount' ? $request->discount : $request['discount'],
                'display_discount' => $request->discount_type == 'amount' ? $request->display_discount : $request['display_discount'],
                'discount_type' => $request->discount_type??'',
                'status' => 1,
                'data' => json_encode($data),
                'is_highlighted' => $request->is_highlighted == 'yes' ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
        else if($request->coupon_type == 'restaurant_wise')
        {
            $data = $request->restaurant_ids;
            //DB::table()->update();
            $awtCouponType = $request->discount_type;
            $awtDP=$request->discount;
            $awtDM=$request->max_discount;

            $awtIsHightligted = $request->is_highlighted;

            if($awtIsHightligted == "yes"){

                $awtCouponStr = $request->title;
                /*if($awtCouponType == "percent"){
                    //$awtCouponStr = $awtDP." % off up to Rs. ".$awtDM;
                    $awtCouponStr = "Up to ".$awtDP."% off + Bank Offer";
                }else{
                    $awtCouponStr = "Up to Rs ".$awtDP." off + Bank Offer";
                }*/

            }else if($awtIsHightligted == "no"){
                $awtCouponStr = "n/a";
            }else{
                $awtCouponStr = "n/a";
            }

            //dd($data);exit;

            for($a=0;$a<sizeof($data);$a++){
                $subArr[]=$data[$a];
                $awtRid=$data[$a];
                DB::table('restaurants')->where(['id' => $awtRid])->update([
                    'awt_offer_string' => $awtCouponStr,
                    'offer_start_date' => $request->start_date,
                    'offer_end_date' => $request->expire_date
                ]);

                DB::table('coupons')->insert([
                    'title' => $request->title,
                    'code' => $request->code,
                    'limit' => $request->coupon_type=='first_order'?1:$request->limit,
                    'coupon_type' => $request->coupon_type,
                    'start_date' => $request->start_date,
                    'expire_date' => $request->expire_date,
                    'min_purchase' => $request->min_purchase != null ? $request->min_purchase : 0,
                    'max_discount' => $request->max_discount != null ? $request->max_discount : 0,
                    'discount' => $request->discount_type == 'amount' ? $request->discount : $request['discount'],
                    'display_discount' => $request->discount_type == 'amount' ? $request->display_discount : $request['display_discount'],
                    'discount_type' => $request->discount_type??'',
                    'status' => 1,
                    'data' => json_encode($subArr),
                    'is_highlighted' => $request->is_highlighted == 'yes' ? 1 : 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                unset($subArr);
            }

            /*DB::table('restaurants')->where(['id' => $request->restaurant_ids])->update([
                'awt_offer_string' => $awtCouponStr
            ]);*/

            /*DB::table('coupons')->insert([
                'title' => $request->title,
                'code' => $request->code,
                'limit' => $request->coupon_type=='first_order'?1:$request->limit,
                'coupon_type' => $request->coupon_type,
                'start_date' => $request->start_date,
                'expire_date' => $request->expire_date,
                'min_purchase' => $request->min_purchase != null ? $request->min_purchase : 0,
                'max_discount' => $request->max_discount != null ? $request->max_discount : 0,
                'discount' => $request->discount_type == 'amount' ? $request->discount : $request['discount'],
                'discount_type' => $request->discount_type??'',
                'status' => 1,
                'data' => json_encode($data),
                'created_at' => now(),
                'updated_at' => now()
            ]);            */
        }



        Toastr::success(translate('messages.coupon_added_successfully'));
        return back();
    }

    public function edit($id)
    {
        $coupon = Coupon::where(['id' => $id])->first();
        // dd(json_decode($coupon->data));
        return view('admin-views.coupon.edit', compact('coupon'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
           // 'code' => 'required|max:100|unique:coupons,code,'.$id,
           'code' => 'required|max:100,'.$id,
            'title' => 'required|max:191',
            'start_date' => 'required',
            'expire_date' => 'required',
            'discount' => 'required',
            'display_discount' => 'required',
            'zone_ids' => 'required_if:coupon_type,zone_wise',
            'restaurant_ids' => 'required_if:coupon_type,restaurant_wise'
        ]);
        $data  = '';
        if($request->coupon_type == 'zone_wise')
        {
            $data = $request->zone_ids;
            $awtIsHightligted = $request->is_highlighted;
            if($awtIsHightligted == "yes"){
                $awtCouponStr = $request->title;
            }else if($awtIsHightligted == "no"){
                $awtCouponStr = "n/a";
            }else{
                $awtCouponStr = "n/a";
            }
            for($a=0;$a<sizeof($data);$a++){
                $awtZoneId=$data[$a];
                $restaurants = DB::table('restaurants')->where('zone_id', $awtZoneId)->get();
                foreach($restaurants as $val){
                    DB::table('restaurants')->where(['id' => $val->id])->update([
                        'awt_offer_string' => $awtCouponStr,
                        'offer_start_date' => $request->start_date,
                        'offer_end_date' => $request->expire_date
                    ]);
                }
            }
        }
        else if($request->coupon_type == 'restaurant_wise')
        {
            $data = $request->restaurant_ids;
            $awtCouponType = $request->discount_type;
            $awtDP=$request->discount;
            $awtDM=$request->max_discount;
            /*if($awtCouponType == "percent"){
                $awtCouponStr = $awtDP." % off up to Rs. ".$awtDM;
            }else{
                $awtCouponStr = $awtDP." Rs. off up to Rs. ".$awtDM;
            }*/
            /*if($awtCouponType == "percent"){
                //$awtCouponStr = $awtDP." % off up to Rs. ".$awtDM;
                $awtCouponStr = "Up to ".$awtDP."% off + Bank Offer";
            }else{
                $awtCouponStr = "Up to Rs ".$awtDP." off + Bank Offer";
            }*/

            $awtIsHightligted = $request->is_highlighted;

            if($awtIsHightligted == "yes"){
                $awtCouponStr = $request->title;

            }else if($awtIsHightligted == "no"){
                $awtCouponStr = "n/a";
            }else{
                $awtCouponStr = "n/a";
            }
            DB::table('restaurants')->where(['id' => $request->restaurant_ids])->update([
                'awt_offer_string' => $awtCouponStr,
                'offer_start_date' => $request->start_date,
                'offer_end_date' => $request->expire_date
            ]);
        }

        DB::table('coupons')->where(['id' => $id])->update([
            'title' => $request->title,
            'code' => $request->code,
            'limit' => $request->coupon_type=='first_order'?1:$request->limit,
            'coupon_type' => $request->coupon_type,
            'start_date' => $request->start_date,
            'expire_date' => $request->expire_date,
            'min_purchase' => $request->min_purchase != null ? $request->min_purchase : 0,
            'max_discount' => $request->max_discount != null ? $request->max_discount : 0,
            'discount' => $request->discount_type == 'amount' ? $request->discount : $request['discount'],
            'display_discount' => $request->discount_type == 'amount' ? $request->display_discount : $request['display_discount'],
            'discount_type' => $request->discount_type??'',
            'data' => json_encode($data),
            'is_highlighted' => $request->is_highlighted == 'yes' ? 1 : 0,
            'updated_at' => now()
        ]);

        Toastr::success(translate('messages.coupon_updated_successfully'));
        return redirect()->route('admin.coupon.add-new');
    }

    public function status(Request $request)
    {
        $coupon = Coupon::find($request->id);
        $coupon->status = $request->status;
        $coupon->save();
        Toastr::success(translate('messages.coupon_status_updated'));
        return back();
    }

    public function delete(Request $request)
    {
        $coupon = Coupon::find($request->id);
        if($coupon->coupon_type == 'restaurant_wise') {
            $dAwt = json_decode($coupon,true);
            $kAnt = json_decode($dAwt['data'],true);
            $couponsRes=Restaurant::where("id",$kAnt[0])->first();
            $resId = json_decode($couponsRes,true);
            $aResId = $resId['id'];
            DB::table('restaurants')->where(['id' => $aResId])->update([
                    'awt_offer_string' => "n/a",
                    'offer_start_date' => null,
                    'offer_end_date' => null
            ]);
        } else if($coupon->coupon_type == 'zone_wise') {
            $data = json_decode($coupon->data, true);
            for($a=0;$a<sizeof($data);$a++){
                $awtZoneId=$data[$a];
                $restaurants = DB::table('restaurants')->where('zone_id', $awtZoneId)->get();
                foreach($restaurants as $val){
                    DB::table('restaurants')->where(['id' => $val->id])->update([
                        'awt_offer_string' => "n/a",
                        'offer_start_date' => null,
                        'offer_end_date' => null
                    ]);
                }
            }
        }
        $coupon->delete();
        Toastr::success(translate('messages.coupon_deleted_successfully'));
        return back();
    }

    public function search(Request $request){
        $key = explode(' ', $request['search']);
        $searchType=$request['awt_search_type'];
        info("coupon restaurant name search - ".$searchType);
        $restaurant = Restaurant::get();
        if($searchType == 0 || $searchType == 3){
            $coupons=Coupon::where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('title', 'like', "%{$value}%")
                    ->orWhere('code', 'like', "%{$value}%")->orWhere('awt_res_name','like',"%{$value}%")->orWhere('awt_zone_name','like',"%{$value}%");
                }
            })->limit(50)->get();

        }else if($searchType == 1){
            $coupons=Coupon::where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('awt_zone_name','like',"%{$value}%");
                }
            })->limit(50)->get();
        }else if($searchType == 2){
             $coupons=Coupon::where('awt_res_name','like',$request['search'])->limit(50)->get();
            // info($key);
            // $coupons=Coupon::where(function ($q) use ($key) {
            //     foreach ($key as $value) {
            //         $q->orWhere('awt_res_name',"{$value}");
            //     }
            // })->limit(50)->get();
        }

        /*$coupons=Coupon::where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('title', 'like', "%{$value}%")
                ->orWhere('code', 'like', "%{$value}%")->orWhere('awt_res_name','like',"%{$value}%")->orWhere('awt_zone_name','like',"%{$value}%");
            }
        })->limit(50)->get();*/
        return response()->json([
            'view'=>view('admin-views.coupon.partials._table',compact('coupons','restaurant'))->render(),
            'count'=>$coupons->count()
        ]);
    }
}
