<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\CouponLogic;
use App\CentralLogics\CustomerLogic;
use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\OrderDetail;
use App\Models\Zone;
use App\Models\DeliveryManWallet;
use App\Models\DeliveryMan;
use App\Models\Category;
use App\Models\Food;
use App\Models\BusinessSetting;
use App\Models\Coupon;
use App\Models\ItemCampaign;
use App\Models\ServiceOrderPidgeLog;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Grimzy\LaravelMysqlSpatial\Types\Point;
use Illuminate\Support\Facades\DB;
use App\Scopes\RestaurantScope;
use Rap2hpoutre\FastExcel\FastExcel;

class OrderController extends Controller
{

    public function awt_accept_return_order(Request $request)
    {

        $awtOrderId = (int) $request['order_id'];
        $order = Order::where('id', $awtOrderId)
            ->Notpos()
            ->first();

        $order->awt_return_order_status = 1;
        $order->save();

        Toastr::success(translate('messages.order') . translate('messages.status_updated'));
        return back();

    }

    public function awt_return_list(Request $request)
    {
        if (session()->has('zone_filter') == false) {
            session()->put('zone_filter', 0);
        }

        if (session()->has('order_filter')) {
            $request = json_decode(session('order_filter'));
        }

        //$orders=Order::where('awt_return_order_reason', '!=' ,'n/a')->orderBy('id', 'desc')->get();
        // dd($orders);
        //->paginate(config('default_pagination'));

        // dd($orders->toSql());exit;
        $status = 'canceled';
        $orders = Order::with(['customer', 'restaurant'])
            ->when(isset($request->zone), function ($query) use ($request) {
                return $query->whereHas('restaurant', function ($q) use ($request) {
                    return $q->whereIn('zone_id', $request->zone);
                });
            })

            ->where('awt_return_order_reason', '!=', 'n/a')
            ->where('order_status', '!=', 'delivered')
            ->orderBy('schedule_at', 'desc')
            ->paginate(config('default_pagination'));

        // dd(config('default_pagination'));exit;

        $orderstatus = isset($request->orderStatus) ? $request->orderStatus : [];
        $scheduled = isset($request->scheduled) ? $request->scheduled : 0;
        $vendor_ids = isset($request->vendor) ? $request->vendor : [];
        $zone_ids = isset($request->zone) ? $request->zone : [];
        $from_date = isset($request->from_date) ? $request->from_date : null;
        $to_date = isset($request->to_date) ? $request->to_date : null;
        $order_type = isset($request->order_type) ? $request->order_type : null;
        $total = $orders->total();
        // $total = 10;

        //dd(json_encode($zone_ids,true));
        return view('admin-views.order.awt_return_list', compact('orders', 'status', 'orderstatus', 'scheduled', 'vendor_ids', 'zone_ids', 'from_date', 'to_date', 'total', 'order_type'));
    }

    public function list($status, Request $request)
    {
        if (session()->has('zone_filter') == false) {
            session()->put('zone_filter', 0);
        }

        if (session()->has('order_filter')) {
            $request = json_decode(session('order_filter'));
        }

        Order::where(['checked' => 0])->update(['checked' => 1]);

        $orders = Order::with(['customer', 'restaurant'])
            ->when(isset($request->zone), function ($query) use ($request) {
                return $query->whereHas('restaurant', function ($q) use ($request) {
                    return $q->whereIn('zone_id', $request->zone);
                });
            })
            ->when($status == 'scheduled', function ($query) {
                return $query->whereRaw('created_at <> schedule_at');
            })
            ->when($status == 'searching_for_deliverymen', function ($query) {
                return $query->SearchingForDeliveryman();
            })
            ->when($status == 'pending', function ($query) {
                return $query->Pending();
            })
            ->when($status == 'accepted', function ($query) {
                return $query->AccepteByDeliveryman();
            })
            ->when($status == 'processing', function ($query) {
                return $query->Preparing();
            })
            ->when($status == 'food_on_the_way', function ($query) {
                return $query->FoodOnTheWay();
            })
            ->when($status == 'delivered', function ($query) {
                return $query->Delivered();
            })
            ->when($status == 'canceled', function ($query) {
                return $query->Canceled();
            })
            ->when($status == 'failed', function ($query) {
                return $query->failed();
            })
            ->when($status == 'refunded', function ($query) {
                return $query->Refunded();
            })
            ->when($status == 'scheduled', function ($query) {
                return $query->Scheduled();
            })
            ->when($status == 'on_going', function ($query) {
                return $query->Ongoing();
            })
            ->when(($status != 'all' && $status != 'scheduled' && $status != 'canceled' && $status != 'refund_requested' && $status != 'refunded' && $status != 'delivered' && $status != 'failed'), function ($query) {
                return $query->OrderScheduledIn(30);
            })
            ->when(isset($request->vendor), function ($query) use ($request) {
                return $query->whereHas('restaurant', function ($query) use ($request) {
                    return $query->whereIn('id', $request->vendor);
                });
            })
            ->when(isset($request->orderStatus) && $status == 'all', function ($query) use ($request) {
                return $query->whereIn('order_status', $request->orderStatus);
            })
            ->when(isset($request->scheduled) && $status == 'all', function ($query) {
                return $query->scheduled();
            })
            ->when(isset($request->order_type), function ($query) use ($request) {
                return $query->where('order_type', $request->order_type);
            })
            ->when(isset($request->from_date) && isset($request->to_date) && $request->from_date != null && $request->to_date != null, function ($query) use ($request) {
                return $query->whereBetween('created_at', [$request->from_date . " 00:00:00", $request->to_date . " 23:59:59"]);
            })
            // ->Notpos()
            ->orderBy('schedule_at', 'desc')
            ->paginate(config('default_pagination'));

        // dd(config('default_pagination'));exit;

        $orderstatus = isset($request->orderStatus) ? $request->orderStatus : [];
        $scheduled = isset($request->scheduled) ? $request->scheduled : 0;
        $vendor_ids = isset($request->vendor) ? $request->vendor : [];
        $zone_ids = isset($request->zone) ? $request->zone : [];
        $from_date = isset($request->from_date) ? $request->from_date : null;
        $to_date = isset($request->to_date) ? $request->to_date : null;
        $order_type = isset($request->order_type) ? $request->order_type : null;
        $total = $orders->total();
        // dd(json_encode($zone_ids,true));
        return view('admin-views.order.list', compact('orders', 'status', 'orderstatus', 'scheduled', 'vendor_ids', 'zone_ids', 'from_date', 'to_date', 'total', 'order_type'));
    }

    public function export_orders($status, $type, Request $request)
    {
        if (session()->has('zone_filter') == false) {
            session()->put('zone_filter', 0);
        }

        if (session()->has('order_filter')) {
            $request = json_decode(session('order_filter'));
        }

        $orders = Order::with(['customer', 'restaurant'])
            ->when(isset($request->zone), function ($query) use ($request) {
                return $query->whereHas('restaurant', function ($q) use ($request) {
                    return $q->whereIn('zone_id', $request->zone);
                });
            })
            ->when($status == 'scheduled', function ($query) {
                return $query->whereRaw('created_at <> schedule_at');
            })
            ->when($status == 'searching_for_deliverymen', function ($query) {
                return $query->SearchingForDeliveryman();
            })
            ->when($status == 'pending', function ($query) {
                return $query->Pending();
            })
            ->when($status == 'accepted', function ($query) {
                return $query->AccepteByDeliveryman();
            })
            ->when($status == 'processing', function ($query) {
                return $query->Preparing();
            })
            ->when($status == 'food_on_the_way', function ($query) {
                return $query->FoodOnTheWay();
            })
            ->when($status == 'delivered', function ($query) {
                return $query->Delivered();
            })
            ->when($status == 'canceled', function ($query) {
                return $query->Canceled();
            })
            ->when($status == 'failed', function ($query) {
                return $query->failed();
            })
            ->when($status == 'refunded', function ($query) {
                return $query->Refunded();
            })
            ->when($status == 'scheduled', function ($query) {
                return $query->Scheduled();
            })
            ->when($status == 'on_going', function ($query) {
                return $query->Ongoing();
            })
            ->when(($status != 'all' && $status != 'scheduled' && $status != 'canceled' && $status != 'refund_requested' && $status != 'refunded' && $status != 'delivered' && $status != 'failed'), function ($query) {
                return $query->OrderScheduledIn(30);
            })
            ->when(isset($request->vendor), function ($query) use ($request) {
                return $query->whereHas('restaurant', function ($query) use ($request) {
                    return $query->whereIn('id', $request->vendor);
                });
            })
            ->when(isset($request->orderStatus) && $status == 'all', function ($query) use ($request) {
                return $query->whereIn('order_status', $request->orderStatus);
            })
            ->when(isset($request->scheduled) && $status == 'all', function ($query) {
                return $query->scheduled();
            })
            ->when(isset($request->order_type), function ($query) use ($request) {
                return $query->where('order_type', $request->order_type);
            })
            ->when(isset($request->from_date) && isset($request->to_date) && $request->from_date != null && $request->to_date != null, function ($query) use ($request) {
                return $query->whereBetween('created_at', [$request->from_date . " 00:00:00", $request->to_date . " 23:59:59"]);
            })
            ->Notpos()
            ->orderBy('schedule_at', 'desc')
            ->get();
        if ($type == 'excel') {
            return (new FastExcel(OrderLogic::format_export_data($orders)))->download('Orders.xlsx');
        } else if ($type == 'csv') {
            return (new FastExcel(OrderLogic::format_export_data($orders)))->download('Orders.csv');
        }
        return (new FastExcel(OrderLogic::format_export_data($orders)))->download('Orders.xlsx');
    }

    public function dispatch_list($status, Request $request)
    {

        if (session()->has('order_filter')) {
            $request = json_decode(session('order_filter'));
            $zone_ids = isset($request->zone) ? $request->zone : 0;
        }

        Order::where(['checked' => 0])->update(['checked' => 1]);

        $orders = Order::with(['customer', 'restaurant'])
            ->when(isset($request->zone), function ($query) use ($request) {
                return $query->whereHas('restaurant', function ($query) use ($request) {
                    return $query->whereIn('zone_id', $request->zone);
                });
            })
            ->when($status == 'searching_for_deliverymen', function ($query) {
                return $query->SearchingForDeliveryman();
            })
            ->when($status == 'on_going', function ($query) {
                return $query->Ongoing();
            })
            ->when(isset($request->vendor), function ($query) use ($request) {
                return $query->whereHas('restaurant', function ($query) use ($request) {
                    return $query->whereIn('id', $request->vendor);
                });
            })
            ->when(isset($request->from_date) && isset($request->to_date) && $request->from_date != null && $request->to_date != null, function ($query) use ($request) {
                return $query->whereBetween('created_at', [$request->from_date . " 00:00:00", $request->to_date . " 23:59:59"]);
            })
            ->Notpos()
            ->OrderScheduledIn(30)
            ->orderBy('schedule_at', 'desc')
            ->paginate(config('default_pagination'));

        $orderstatus = isset($request->orderStatus) ? $request->orderStatus : [];
        $scheduled = isset($request->scheduled) ? $request->scheduled : 0;
        $vendor_ids = isset($request->vendor) ? $request->vendor : [];
        $zone_ids = isset($request->zone) ? $request->zone : [];
        $from_date = isset($request->from_date) ? $request->from_date : null;
        $to_date = isset($request->to_date) ? $request->to_date : null;
        $total = $orders->total();

        return view('admin-views.order.distaptch_list', compact('orders', 'status', 'orderstatus', 'scheduled', 'vendor_ids', 'zone_ids', 'from_date', 'to_date', 'total'));
    }

    public function details(Request $request, $id)
    {
        $order = Order::with([
            'details',
            'restaurant' => function ($query) {
                return $query->withCount('orders');
            },
            'customer' => function ($query) {
                return $query->withCount('orders');
            },
            'delivery_man' => function ($query) {
                return $query->withCount('orders');
            },
            'details.food' => function ($query) {
                return $query->withoutGlobalScope(RestaurantScope::class);
            },
            'details.campaign' => function ($query) {
                return $query->withoutGlobalScope(RestaurantScope::class);
            }
        ])->where(['id' => $id])->Notpos()->first();
        if (isset($order)) {
            if (isset($order->restaurant) && $order->restaurant->self_delivery_system) {
                $deliveryMen = DeliveryMan::where('restaurant_id', $order->restaurant_id)->available()->active()->get();
            } else {
                if($order->restaurant->restaurant_type == 'general') {
                    if ($order->restaurant !== null) {
                        $deliveryMen = DeliveryMan::where('zone_id', $order->restaurant->zone_id)->available()->active()->get();
                    } else {
                        $deliveryMen = DeliveryMan::where('zone_id', '=', NULL)->active()->get();
                    }
                } else {
                    $deliveryMen = DeliveryMan::where('restaurant_id', $order->restaurant_id)->available()->active()->get();
                }
            }

            $category = $request->query('category_id', 0);
            // $sub_category = $request->query('sub_category', 0);
            $categories = Category::active()->get();
            $keyword = $request->query('keyword', false);
            $key = explode(' ', $keyword);
            $products = Food::withoutGlobalScope(RestaurantScope::class)->where('restaurant_id', $order->restaurant_id)
                ->when($category, function ($query) use ($category) {
                    $query->whereHas('category', function ($q) use ($category) {
                        return $q->whereId($category)->orWhere('parent_id', $category);
                    });
                })
                ->when($keyword, function ($query) use ($key) {
                    return $query->where(function ($q) use ($key) {
                        foreach ($key as $value) {
                            $q->orWhere('name', 'like', "%{$value}%");
                        }
                    });
                })
                ->latest()->paginate(10);
            $editing = false;
            if ($request->session()->has('order_cart')) {
                $cart = session()->get('order_cart');
                if (count($cart) > 0 && $cart[0]->order_id == $order->id) {
                    $editing = true;
                } else {
                    session()->forget('order_cart');
                }
            }

            $deliveryMen = Helpers::deliverymen_list_formatting($deliveryMen);
            $pidge_logs = ServiceOrderPidgeLog::where('service_order_id', $id)
                ->where('action_type', 'canceled')
                ->first();
            $pidge_logs = $pidge_logs ? 1 : 0;

            $pidge_data = ServiceOrderPidgeLog::where('service_order_id', $id)
                ->where('action_type', 'OUT_FOR_PICKUP')
                ->first();

            // return $pidge_logs;
            return view('admin-views.order.order-view', compact('order', 'deliveryMen', 'categories', 'products', 'category', 'keyword', 'editing', 'pidge_logs', 'pidge_data'));
        } else {
            Toastr::info(translate('messages.no_more_orders'));
            return back();
        }
    }

    public function search(Request $request)
    {
        $key = explode(' ', $request['search']);
        $orders = Order::where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('id', 'like', "%{$value}%")
                    ->orWhere('order_status', 'like', "%{$value}%")
                    ->orWhere('transaction_reference', 'like', "%{$value}%");
            }
        })->Notpos()->limit(50)->get();
        return response()->json([
            'view' => view('admin-views.order.partials._table', compact('orders'))->render()
        ]);
    }

    public function status(Request $request)
    {
        $order = Order::Notpos()->find($request->id);
        if (in_array($order->order_status, ['delivered', 'refunded', 'failed'])) {
            Toastr::warning(translate('messages.you_can_not_change_the_status_of_a_completed_order'));
            return back();
        }

        if ($order['delivery_man_id'] == null && $request->order_status == 'picked_up') {
            Toastr::warning(translate('messages.please_assign_deliveryman_first'));
            return back();
        }

        // if ($order['delivery_man_id'] == null && $request->order_status == 'delivered') {
        //     Toastr::warning(translate('messages.please_assign_deliveryman_first'));
        //     return back();
        // }

        if ($request->order_status == 'delivered' && $order['transaction_reference'] == null && !in_array($order['payment_method'], ['cash_on_delivery', 'wallet'])) {
            Toastr::warning(translate('messages.add_your_paymen_ref_first'));
            return back();
        }

        if ($request->order_status == 'delivered') {

            // For Refer and Earn Concept
            $user_data_checking = User::where('id', $order->user_id)->first();
            if ($user_data_checking->referred_by != '') {
                $previous_completed_orders = Order::where('user_id', $user_data_checking->id)->where('order_status', 'delivered')->get();
                if (count($previous_completed_orders) == 0) {
                    $referrer_earning = BusinessSetting::where('key', 'ref_earning_exchange_rate')
                        ->value('value');
                    $referrer_wallet_transaction = CustomerLogic::create_wallet_transaction($user_data_checking->referred_by, $referrer_earning, 'add_fund', 'Referral Earning');

                    $firebase_token = User::where('id', $user_data_checking->referred_by)->value('cm_firebase_token');
                    $ios_firebase_token = User::where('id', $user_data_checking->referred_by)->value('cm_firebase_token_ios');
                    $data = [
                        'title' => 'Referal Earning',
                        'description' => 'Added a Referral Earning of Rs.' . $referrer_earning . ' to your Wallet',
                        'order_id' => $order['id'],
                        'image' => '',
                        'type' => 'order_status',
                    ];
                    Helpers::send_push_notif_to_device($firebase_token, $data);
                    Helpers::send_push_notif_to_device($ios_firebase_token, $data);
                }
            }

            if ($order->transaction == null) {
                if ($order->payment_method == "cash_on_delivery") {
                    if ($order->order_type == 'take_away') {
                        $ol = OrderLogic::create_transaction($order, 'restaurant', null);
                    } else if ($order->delivery_man_id) {
                        $ol = OrderLogic::create_transaction($order, 'deliveryman', null);
                    } else if ($order->user_id) {
                        $ol = OrderLogic::create_transaction($order, false, null);
                    }
                } else {
                    $ol = OrderLogic::create_transaction($order, 'admin', null);
                }
                if (!$ol) {
                    Toastr::warning(translate('messages.faield_to_create_order_transaction'));
                    return back();
                }
            } else if ($order->delivery_man_id) {
                $order->transaction->update(['delivery_man_id' => $order->delivery_man_id]);
            }

            $order->payment_status = 'paid';
            if ($order->delivery_man) {
                $dm = $order->delivery_man;
                $dm->increment('order_count');
                $dm->current_orders = $dm->current_orders > 1 ? $dm->current_orders - 1 : 0;
                $dm->save();
            }
            $order->details->each(function ($item, $key) {
                if ($item->food) {
                    $item->food->increment('order_count');
                }
            });
            $order->customer->increment('order_count');
            $order->restaurant->increment('order_count');
            Helpers::petpoojaRiderStatusUpdate($order->id, 'delivered');
        } else if ($request->order_status == 'refunded') {
            if (($order->payment_method == "cash_on_delivery" || $order->payment_status == "unpaid") && $order->wallet_amount != 0) {
                if (BusinessSetting::where('key', 'wallet_add_refund')->first()->value == 1) {
                    CustomerLogic::create_wallet_transaction($order->user_id, $order->wallet_amount, 'order_refund', $order->id);
                }
                // Toastr::warning(translate('messages.you_can_not_refund_a_cod_order'));
                // return back();
            }
            if (isset($order->delivered)) {
                $rt = OrderLogic::refund_order($order);

                if (!$rt) {
                    Toastr::warning(translate('messages.faield_to_create_order_transaction'));
                    return back();
                }
            }

            if ($order->payment_status == "paid" && BusinessSetting::where('key', 'wallet_add_refund')->first()->value == 1) {
                CustomerLogic::create_wallet_transaction($order->user_id, $order->order_amount , 'order_refund', $order->id);
                // CustomerLogic::create_wallet_transaction($order->user_id, $order->order_amount + $order->restaurant_discount_amount, 'order_refund', $order->id);
            }

            if ($order->delivery_man) {
                $dm = $order->delivery_man;
                $dm->current_orders = $dm->current_orders > 1 ? $dm->current_orders - 1 : 0;
                $dm->save();
            }
            Helpers::petpoojaOrderStatusUpdate($order->id, '-1', 'The Admin Cancelled the Order');
        } else if ($request->order_status == 'canceled') {
            info("awt order cancelled");
            if (in_array($order->order_status, ['delivered', 'canceled', 'refund_requested', 'refunded', 'failed'])) {
                Toastr::warning(translate('messages.you_can_not_cancel_a_completed_order'));
                return back();
            }
            if ($order->delivery_man) {
                $dm = $order->delivery_man;
                $dm->current_orders = $dm->current_orders > 1 ? $dm->current_orders - 1 : 0;
                $dm->save();
            }
            $order->canceled = now();
            Helpers::petpoojaOrderStatusUpdate($order->id, '-1', 'The Admin Cancelled the Order');
        }
        if ($request->order_status == 'picked_up') {
            Helpers::petpoojaRiderStatusUpdate($order->id, 'pickedup');
        }
        $order->order_status = $request->order_status;
        if ($request->order_status == 'processing') {
            $order->processing_time = isset($request->processing_time) ? $request->processing_time : explode('-', $order['restaurant']['delivery_time'])[0];

            $first_parameter = json_decode($request->processing_time, true);
            $awt_sub_str2 = date('d-m-Y h:i:s');
            $time1 = strtotime($awt_sub_str2);
            $time2 = $time1 + ($first_parameter * 60);
            $awt_time = date("d-m-Y, H:i:s", $time2);
            $awt_delivery_boy_call_time = $awt_time;
            $order->awt_delivery_boy_call_time = $awt_delivery_boy_call_time;
            //  $awt_str1 = explode(" ",$first_parameter);
            //  $awt_str2 = explode("-",$awt_str1[0]);
            //  dd($first_parameter);exit;
            //  $awt_sub_str2 = $awt_str2[2]."-".$awt_str2[1]."-".$awt_str2[0]." ".$awt_str1[1];
            // $awt_sub_str2="28-10-2020 13:20:00";
            //  $time1 = strtotime($awt_sub_str2);
            //  $time2 = $time1 + (15 * 60);
            //  $awt_time = date("d-m-Y, H:i:s", $time2);
            //  $awt_delivery_boy_call_time=$awt_time;
            //   $order->awt_delivery_boy_call_time = $awt_delivery_boy_call_time;

        }
        $order[$request->order_status] = now();
        $order->save();

        if (!Helpers::send_order_notification($order)) {
            Toastr::warning(translate('messages.push_notification_faild'));
        }
        $order_id = $order['id'];
        $user_id = $order['user_id'];
        $awt_status = $order['order_status'];
        $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status);
        Toastr::success(translate('messages.order') . translate('messages.status_updated'));
        return back();
    }

    public function add_delivery_man($order_id, $delivery_man_id)
    {
        info("add_Delivery_man id - " . $order_id . " Delivery Man id -" . $delivery_man_id);
        if ($delivery_man_id == 0) {
            return response()->json(['message' => translate('messages.deliveryman') . ' ' . translate('messages.not_found')], 404);
        }
        $order = Order::Notpos()->find($order_id);

        $deliveryman = DeliveryMan::where('id', $delivery_man_id)->available()->active()->first();
        if ($order->delivery_man_id == $delivery_man_id) {
            return response()->json(['message' => translate('messages.order_already_assign_to_this_deliveryman')], 400);
        }
        if ($deliveryman) {
            if ($deliveryman->current_orders >= config('dm_maximum_orders')) {
                return response()->json(['message' => translate('messages.dm_maximum_order_exceed_warning')], 400);
            }

            //check here

            $cash_in_hand = isset($deliveryman->wallet) ? $deliveryman->wallet->collected_cash : 0;

            $dm_max_cash = BusinessSetting::where('key', 'dm_max_cash_in_hand')->first();

            $value = $dm_max_cash ? $dm_max_cash->value : 0;

            if ($order->payment_method == "cash_on_delivery" && (($cash_in_hand + $order->order_amount) >= $value)) {
                return response()->json(['message' => translate('delivery man max cash in hand exceeds')], 400);
            }
            $order->delivery_man_id = $delivery_man_id;
            $order->order_status = in_array($order->order_status, ['pending', 'confirmed']) ? 'accepted' : $order->order_status;
            $order->accepted = now();
            $order->save();

            $deliveryman->current_orders = $deliveryman->current_orders + 1;
            $deliveryman->save();
            $deliveryman->increment('assigned_order_count');


            if ($order->delivery_man) {
                $dm = $order->delivery_man;
                $dm->current_orders = $dm->current_orders > 1 ? $dm->current_orders - 1 : 0;
                $dm->save();

                $data = [
                    'title' => translate('messages.order_push_title'),
                    'description' => translate('messages.you_are_unassigned_from_a_order'),
                    'order_id' => '',
                    'image' => '',
                    'type' => 'assign'
                ];
                Helpers::send_push_notif_to_device($dm->fcm_token, $data);

                DB::table('user_notifications')->insert([
                    'data' => json_encode($data),
                    'delivery_man_id' => $dm->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
            $order->delivery_man_id = $delivery_man_id;
            $order->order_status = in_array($order->order_status, ['pending', 'confirmed']) ? 'accepted' : $order->order_status;
            $order->accepted = now();
            $order->save();

            $deliveryman->current_orders = $deliveryman->current_orders + 1;
            $deliveryman->save();
            $deliveryman->increment('assigned_order_count');
            $value = Helpers::order_status_update_message('accepted');
            try {
                if ($value && $order->customer) {
                    $fcm_token = $order->customer->cm_firebase_token;
                    $data = [
                        'title' => translate('messages.order_push_title'),
                        'description' => $value,
                        'order_id' => $order['id'],
                        'image' => '',
                        'type' => 'order_status'
                    ];
                    Helpers::send_push_notif_to_device($fcm_token, $data);

                    DB::table('user_notifications')->insert([
                        'data' => json_encode($data),
                        'user_id' => $order->customer->id,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
                $data = [
                    'title' => translate('messages.order_push_title'),
                    'description' => translate('messages.you_are_assigned_to_a_order'),
                    'order_id' => $order['id'],
                    'image' => '',
                    'type' => 'assign'
                ];
                Helpers::send_push_notif_to_device($deliveryman->fcm_token, $data);
                DB::table('user_notifications')->insert([
                    'data' => json_encode($data),
                    'delivery_man_id' => $deliveryman->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            } catch (\Exception $e) {
                info($e);
                Toastr::warning(translate('messages.push_notification_faild'));
            }
            return response()->json([], 200);
        }
        return response()->json(['message' => translate('Deliveryman not available!')], 400);
    }

    public function update_shipping(Request $request, Order $order)
    {

        $request->validate([
            'contact_person_name' => 'required',
            'address_type' => 'required',
            'contact_person_number' => 'required',
        ]);
        if ($request->latitude && $request->longitude) {
            $point = new Point($request->latitude, $request->longitude);
            $zone = Zone::where('id', $order->restaurant->zone_id)->contains('coordinates', $point)->first();
            if (!$zone) {
                Toastr::error(translate('messages.out_of_coverage'));
                return back();
            }
        }
        $address = [
            'contact_person_name' => $request->contact_person_name,
            'contact_person_number' => $request->contact_person_number,
            'address_type' => $request->address_type,
            'address' => $request->address,
            'longitude' => $request->longitude,
            'latitude' => $request->latitude,
            'floor' => $request->floor,
            'house' => $request->house,
            'road' => $request->road,
        ];

        $order->delivery_address = json_encode($address);
        $order->save();
        Toastr::success(translate('messages.delivery_address_updated'));
        return back();
    }

    public function generate_invoice($id)
    {
        $order = Order::Notpos()->where('id', $id)->first();
        return view('admin-views.order.invoice', compact('order'));
    }

    public function add_payment_ref_code(Request $request, $id)
    {
        $request->validate([
            'transaction_reference' => 'max:30'
        ]);
        Order::Notpos()->where(['id' => $id])->update([
            'transaction_reference' => $request['transaction_reference']
        ]);

        Toastr::success(translate('messages.payment_reference_code_is_added'));
        return back();
    }

    public function restaurnt_filter($id)
    {
        session()->put('restaurnt_filter', $id);
        return back();
    }

    public function filter(Request $request)
    {
        $request->validate([
            'from_date' => 'required_if:to_date,true',
            'to_date' => 'required_if:from_date,true',
        ]);
        session()->put('order_filter', json_encode($request->all()));
        return back();
    }
    public function filter_reset(Request $request)
    {
        session()->forget('order_filter');
        return back();
    }

    public function add_to_cart(Request $request)
    {
        if ($request->item_type == 'food') {
            $product = Food::withOutGlobalScope(RestaurantScope::class)->find($request->id);
        } else {
            $product = ItemCampaign::withOutGlobalScope(RestaurantScope::class)->find($request->id);
        }

        $data = new OrderDetail();
        if ($request->order_details_id) {
            $data['id'] = $request->order_details_id;
        }

        $data['food_id'] = $request->item_type == 'food' ? $product->id : null;
        $data['item_campaign_id'] = $request->item_type == 'campaign' ? $product->id : null;
        $data['food'] = $request->item_type == 'food' ? $product : null;
        $data['item_campaign'] = $request->item_type == 'campaign' ? $product : null;
        $data['order_id'] = $request->order_id;
        $str = '';
        $price = 0;
        $addon_price = 0;

        //Gets all the choice values of customer choice option and generate a string like Black-S-Cotton
        foreach (json_decode($product->choice_options) as $key => $choice) {
            if ($str != null) {
                $str .= '-' . str_replace(' ', '', $request[$choice->name]);
            } else {
                $str .= str_replace(' ', '', $request[$choice->name]);
            }
        }
        $data['variant'] = json_encode([]);
        $data['variation'] = json_encode([]);
        if ($request->session()->has('order_cart') && !isset($request->cart_item_key)) {
            if (count($request->session()->get('order_cart')) > 0) {
                foreach ($request->session()->get('order_cart') as $key => $cartItem) {
                    if ($cartItem['food_id'] == $request['id'] && $cartItem['status'] == true) {
                        if (count(json_decode($cartItem['variation'], true)) > 0) {
                            if (json_decode($cartItem['variation'], true)[0]['type'] == $str) {
                                return response()->json([
                                    'data' => 1
                                ]);
                            }
                        } else {
                            return response()->json([
                                'data' => 1
                            ]);
                        }
                    }
                }
            }
        }
        //Check the string and decreases quantity for the stock
        if ($str != null) {
            $count = count(json_decode($product->variations));
            for ($i = 0; $i < $count; $i++) {
                if (json_decode($product->variations)[$i]->type == $str) {
                    $price = json_decode($product->variations)[$i]->price;
                }
            }
            $data['variation'] = json_encode([["type" => $str, "price" => $price]]);
        } else {
            $price = $product->price;
        }

        $data['quantity'] = $request['quantity'];
        $data['price'] = $price;
        $data['status'] = true;
        $data['discount_on_food'] = Helpers::product_discount_calculate($product, $price, $product->restaurant);
        $data["discount_type"] = "discount_on_product";
        $data["tax_amount"] = Helpers::tax_calculate($product, $price);
        $add_ons = [];
        $add_on_qtys = [];

        if ($request['addon_id']) {
            foreach ($request['addon_id'] as $id) {
                $addon_price += $request['addon-price' . $id] * $request['addon-quantity' . $id];
                $add_on_qtys[] = $request['addon-quantity' . $id];
            }
            $add_ons = $request['addon_id'];
        }

        $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id', $add_ons)->get(), $add_on_qtys);
        $data['add_ons'] = json_encode($addon_data['addons']);
        $data['total_add_on_price'] = $addon_data['total_add_on_price'];
        // dd($data);
        $cart = $request->session()->get('order_cart', collect([]));
        if (isset($request->cart_item_key)) {
            $cart[$request->cart_item_key] = $data;
            return response()->json([
                'data' => 2
            ]);
        } else {
            $cart->push($data);
        }

        return response()->json([
            'data' => 0
        ]);
    }

    public function remove_from_cart(Request $request)
    {
        $cart = $request->session()->get('order_cart', collect([]));
        $count = 0;
        foreach ($cart as $val) {
            if ($val->status == true) {
                $count++;
            }
        }
        if (count($cart) > 1) {
            if ($count > 1)
                $cart[$request->key]->status = false;

            $request->session()->put('order_cart', $cart);

            return response()->json($cart, 200);
        }
        return response()->json([], 200);
    }

    public function edit(Request $request, Order $order)
    {
        $order = Order::withoutGlobalScope(RestaurantScope::class)->with([
            'details',
            'restaurant' => function ($query) {
                return $query->withCount('orders');
            },
            'customer' => function ($query) {
                return $query->withCount('orders');
            },
            'delivery_man' => function ($query) {
                return $query->withCount('orders');
            },
            'details' => function ($query) {
                return $query->with([
                    'food' => function ($q) {
                        return $q->withoutGlobalScope(RestaurantScope::class);
                    },
                    'campaign' => function ($q) {
                        return $q->withoutGlobalScope(RestaurantScope::class);
                    }
                ]);
            }
        ])->where(['id' => $order->id])->Notpos()->first();
        if ($request->cancle) {
            if ($request->session()->has(['order_cart'])) {
                session()->forget(['order_cart']);
            }
            return back();
        }
        $cart = collect([]);
        foreach ($order->details as $details) {
            unset($details['food_details']);
            $details['status'] = true;
            $cart->push($details);
        }

        if ($request->session()->has('order_cart')) {
            session()->forget('order_cart');
        } else {
            $request->session()->put('order_cart', $cart);
        }
        return back();
    }

    public function update(Request $request, Order $order)
    {
        //info("admin panel order chagne status functioon called");
        $order = Order::with([
            'details',
            'restaurant' => function ($query) {
                return $query->withCount('orders');
            },
            'customer' => function ($query) {
                return $query->withCount('orders');
            },
            'delivery_man' => function ($query) {
                return $query->withCount('orders');
            },
            'details.food' => function ($query) {
                return $query->withoutGlobalScope(RestaurantScope::class);
            },
            'details.campaign' => function ($query) {
                return $query->withoutGlobalScope(RestaurantScope::class);
            }
        ])->where(['id' => $order->id])->Notpos()->first();

        if (!$request->session()->has('order_cart')) {
            Toastr::error(translate('messages.order_data_not_found'));
            return back();
        }
        $cart = $request->session()->get('order_cart', collect([]));
        $restaurant = $order->restaurant;
        $coupon = null;
        $total_addon_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;
        if ($order->coupon_code) {
            $coupon = Coupon::where(['code' => $request['coupon_code']])->first();
        }
        foreach ($cart as $c) {
            if ($c['status'] == true) {
                unset($c['food']);
                unset($c['status']);
                unset($c['item_campaign']);
                if ($c['item_campaign_id'] != null) {
                    $product = ItemCampaign::find($c['item_campaign_id']);
                    if ($product) {

                        $price = $c['price'];

                        $product = Helpers::product_data_formatting($product);

                        $c->food_details = json_encode($product);
                        $c->updated_at = now();
                        if (isset($c->id)) {
                            OrderDetail::where('id', $c->id)->update(
                                [
                                    'food_id' => $c->food_id,
                                    'item_campaign_id' => $c->item_campaign_id,
                                    'food_details' => $c->food_details,
                                    'quantity' => $c->quantity,
                                    'price' => $c->price,
                                    'tax_amount' => $c->tax_amount,
                                    'discount_on_food' => $c->discount_on_food,
                                    'discount_type' => $c->discount_type,
                                    'variant' => $c->variant,
                                    'variation' => $c->variation,
                                    'add_ons' => $c->add_ons,
                                    'total_add_on_price' => $c->total_add_on_price,
                                    'updated_at' => $c->updated_at
                                ]
                            );
                        } else {
                            $c->save();
                        }

                        $total_addon_price += $c['total_add_on_price'];
                        $product_price += $price * $c['quantity'];
                        $restaurant_discount_amount += $c['discount_on_food'] * $c['quantity'];
                    } else {
                        Toastr::error(translate('messages.food_not_found'));
                        return back();
                    }
                } else {
                    $product = Food::find($c['food_id']);
                    if ($product) {

                        $price = $c['price'];

                        $product = Helpers::product_data_formatting($product);

                        $c->food_details = json_encode($product);
                        $c->updated_at = now();
                        if (isset($c->id)) {
                            OrderDetail::where('id', $c->id)->update(
                                [
                                    'food_id' => $c->food_id,
                                    'item_campaign_id' => $c->item_campaign_id,
                                    'food_details' => $c->food_details,
                                    'quantity' => $c->quantity,
                                    'price' => $c->price,
                                    'tax_amount' => $c->tax_amount,
                                    'discount_on_food' => $c->discount_on_food,
                                    'discount_type' => $c->discount_type,
                                    'variant' => $c->variant,
                                    'variation' => $c->variation,
                                    'add_ons' => $c->add_ons,
                                    'total_add_on_price' => $c->total_add_on_price,
                                    'updated_at' => $c->updated_at
                                ]
                            );
                        } else {
                            $c->save();
                        }

                        $total_addon_price += $c['total_add_on_price'];
                        $product_price += $price * $c['quantity'];
                        $restaurant_discount_amount += $c['discount_on_food'] * $c['quantity'];
                    } else {
                        Toastr::error(translate('messages.food_not_found'));
                        return back();
                    }
                }
            } else {
                $c->delete();
            }
        }

        $restaurant_discount = Helpers::get_restaurant_discount($restaurant);
        if (isset($restaurant_discount)) {
            if ($product_price + $total_addon_price < $restaurant_discount['min_purchase']) {
                $restaurant_discount_amount = 0;
            }

            if ($restaurant_discount_amount > $restaurant_discount['max_discount']) {
                $restaurant_discount_amount = $restaurant_discount['max_discount'];
            }
        }
        $order->delivery_charge = $order->original_delivery_charge;
        if ($coupon) {
            if ($coupon->coupon_type == 'free_delivery') {
                $order->delivery_charge = 0;
                $coupon = null;
            }
        }

        if ($order->restaurant->free_delivery) {
            $order->delivery_charge = 0;
        }

        $coupon_discount_amount = $coupon ? CouponLogic::get_discount($coupon, $product_price + $total_addon_price - $restaurant_discount_amount) : 0;
        $total_price = $product_price + $total_addon_price - $restaurant_discount_amount - $coupon_discount_amount;

        $tax = $restaurant->tax;
        $total_tax_amount = ($tax > 0) ? (($total_price * $tax) / 100) : 0;
        if ($restaurant->minimum_order > $product_price + $total_addon_price) {
            Toastr::error(translate('messages.you_need_to_order_at_least', ['amount' => $restaurant->minimum_order . ' ' . Helpers::currency_code()]));
            return back();
        }

        $free_delivery_over = BusinessSetting::where('key', 'free_delivery_over')->first()->value;
        if (isset($free_delivery_over)) {
            if ($free_delivery_over <= $product_price + $total_addon_price - $coupon_discount_amount - $restaurant_discount_amount) {
                $order->delivery_charge = 0;
            }
        }
        $total_order_ammount = $total_price + $total_tax_amount + $order->delivery_charge;
        $adjustment = $order->order_amount - $total_order_ammount;

        $order->coupon_discount_amount = $coupon_discount_amount;
        $order->restaurant_discount_amount = $restaurant_discount_amount;
        $order->total_tax_amount = $total_tax_amount;
        $order->order_amount = $total_order_ammount;
        $order->adjusment = $adjustment;
        $order->edited = true;
        $order->save();
        session()->forget('order_cart');
        Toastr::success(translate('messages.order_updated_successfully'));
        return back();
    }

    public function quick_view(Request $request)
    {
        $product = Food::withOutGlobalScope(RestaurantScope::class)->findOrFail($request->product_id);
        $item_type = 'food';
        $order_id = $request->order_id;

        return response()->json([
            'success' => 1,
            'view' => view('admin-views.order.partials._quick-view', compact('product', 'order_id', 'item_type'))->render(),
        ]);
    }

    public function quick_view_cart_item(Request $request)
    {
        $cart_item = session('order_cart')[$request->key];

        $order_id = $request->order_id;
        $item_key = $request->key;
        $product = $cart_item->food ? $cart_item->food : $cart_item->campaign;
        $item_type = $cart_item->food ? 'food' : 'campaign';
        $add_on = [];
        foreach (json_decode($cart_item->add_ons) as $add_ons) {
            $add_on[] = $add_ons->id;
        }
        $cart_item['add_ons_new'] = $add_on;
        return response()->json([
            'success' => 1,
            'view' => view('admin-views.order.partials._quick-view-cart-item', compact('order_id', 'product', 'cart_item', 'item_key', 'item_type'))->render(),
        ]);
    }

    public function orders_export(Request $request, $type, $restaurant_id)
    {

        $orders = Order::with('customer')->where(['restaurant_id' => $restaurant_id])->get();
        if ($type == 'excel') {
            return (new FastExcel(Helpers::export_restaurant_orders($orders)))->download('Orders.xlsx');
        } elseif ($type == 'csv') {
            return (new FastExcel(Helpers::export_restaurant_orders($orders)))->download('Orders.csv');
        }
    }

    public function restaurant_order_search(Request $request)
    {
        $key = explode(' ', $request['search']);
        $orders = Order::where(['restaurant_id' => $request->restaurant_id])
            ->where(function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('id', 'like', "%{$value}%");

                }
            })
            ->whereHas('customer', function ($q) use ($key) {
                foreach ($key as $value) {
                    $q->orWhere('f_name', 'like', "%{$value}%")
                        ->orWhere('l_name', 'like', "%{$value}%");
                }
            })->get();


        return response()->json([
            'view' => view('admin-views.vendor.view.partials._orderTable', compact('orders'))->render()
        ]);
    }

    public function assign_delivery_pidge($id)
    {
        $order = Order::with([
            'details',
            'restaurant' => function ($query) {
                return $query->withCount('orders');
            },
            'customer' => function ($query) {
                return $query->withCount('orders');
            },
            'delivery_man' => function ($query) {
                return $query->withCount('orders');
            },
            'details.food' => function ($query) {
                return $query->withoutGlobalScope(RestaurantScope::class);
            },
            'details.campaign' => function ($query) {
                return $query->withoutGlobalScope(RestaurantScope::class);
            }
        ])->where(['id' => $id])->Notpos()->first();

        return Helpers::sendPidgeNotification($order);
    }

    public function assign_delivery_pidge_cancel($id)
    {
        $order = Order::with([
            'details',
            'restaurant' => function ($query) {
                return $query->withCount('orders');
            },
            'customer' => function ($query) {
                return $query->withCount('orders');
            },
            'delivery_man' => function ($query) {
                return $query->withCount('orders');
            },
            'details.food' => function ($query) {
                return $query->withoutGlobalScope(RestaurantScope::class);
            },
            'details.campaign' => function ($query) {
                return $query->withoutGlobalScope(RestaurantScope::class);
            }
        ])->where(['id' => $id])->Notpos()->first();

        return Helpers::cancelPidgeOrder($order->pidge_task_id);
    }

    public function pidge_logs($id)
    {
        $pidge_logs = ServiceOrderPidgeLog::where('service_order_id', $id)->get();
        $pidge_logs_data = "";
        if ($pidge_logs->isNotEmpty()) {
            foreach ($pidge_logs as $key => $pidge_log) {
                // $pidge_logs_data .= "<div class='row mb-3 font-weight-bold'>
                //                 <div class='col-sm-1'>".($key+1)."</div>
                //                 <div class='col-sm-2'>".strtoupper($pidge_log->action_type)."</div>
                //                 <div class='col-sm-3'>".($pidge_log->action_response)."</div>
                //                 <div class='col-sm-3'>".date('d M Y ' . config('timeformat'), strtotime($pidge_log->action_triggered_at))."</div>
                //                 <div class='col-sm-3'>".strtoupper($pidge_log->ip_address)."</div>

                //             </div>";
                $pidge_logs_data .= "<tr><td>" . ($key + 1) . "</td>
<td>" . strtoupper($pidge_log->action_type) . "</td>
<td>" . ($pidge_log->action_response) . "</td>
<td>" . date('d M Y ' . config('timeformat'), strtotime($pidge_log->action_triggered_at)) . "</td>
<td>" . strtoupper($pidge_log->ip_address) . "</td></tr>";
            }
        } else
            $pidge_logs_data = "<tr><td colspan='5' align='center' style='font-size: 18px;font-weight: 900;color: red;'>No Data Found</td></tr>";

        return $pidge_logs_data;
    }
}
