<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use App\CentralLogics\RestaurantLogic;
use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\Order;
use App\Models\Notification;
use App\Models\UserNotification;
use App\Models\Campaign;
use App\Models\WithdrawRequest;
use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Rap2hpoutre\FastExcel\FastExcel;

class VendorAwtController extends Controller
{
	public function awt_order_auto_assign_cron_job(){
//$paginator = Order::with(['restaurant', 'delivery_man.rating'])->withCount('details')->where(['user_id' => $request->user()->id])->whereIn('order_status', ['delivered','canceled','refund_requested','refunded','failed'])->Notpos()->latest()->paginate($request['limit'], ['*'], 'page', $request['offset']);
$order = Order::where(['is_delivery_call_done' => 0])->whereIn('order_status', ['processing'])->latest();
       dd($order);
	}
}
