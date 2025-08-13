<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\CustomerLogic;
use App\Models\BusinessSetting;
use App\Models\Order;
use App\Models\ServiceOrderPidgeLog;
use App\Models\User;

ini_set('memory_limit', '-1');

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;

class PidgeController extends Controller
{
    public function index(Request $request)
    {
        // return $request;
        info('Pidge Webhook response..........' . print_r($request->all(), true));

        // Retrieve the JSON payload from the request
        $data = $request->json()->all();

        // Fetch order details based on the retrieved ID
        $order = Order::where('pidge_task_id', $data['id'])->first();
        if ($data['fulfillment']['status'] == 'OUT_FOR_PICKUP') {
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

            $pidge_log = new ServiceOrderPidgeLog();
            $pidge_log->service_order_id = $order->id;
            $pidge_log->action_type = 'OUT_FOR_PICKUP';
            $pidge_log->action_triggered_at = date('Y-m-d H:i:s');
            $pidge_log->action_response = json_encode($data);
            $pidge_log->request_id = (string) $data['id'];
            $pidge_log->ip_address = request()->ip();
            $pidge_log->save();
        } else if ($data['fulfillment']['status'] == 'PICKED_UP') {
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

            $pidge_log = new ServiceOrderPidgeLog();
            $pidge_log->service_order_id = $order->id;
            $pidge_log->action_type = 'PICKED_UP';
            $pidge_log->action_triggered_at = date('Y-m-d H:i:s');
            $pidge_log->action_response = json_encode($data);
            $pidge_log->request_id = (string) $data['id'];
            $pidge_log->ip_address = request()->ip();
            $pidge_log->save();
        } else if ($data['fulfillment']['status'] == 'DELIVERED') {
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

            $pidge_log = new ServiceOrderPidgeLog();
            $pidge_log->service_order_id = $order->id;
            $pidge_log->action_type = 'DELIVERED';
            $pidge_log->action_triggered_at = date('Y-m-d H:i:s');
            $pidge_log->action_response = json_encode($data);
            $pidge_log->request_id = (string) $data['id'];
            $pidge_log->ip_address = request()->ip();
            $pidge_log->save();
        }
        $order->save();
        info('Pidge-Status-check-cronjob-executed-successfully');

        return response()->json(['message' => 'successfully updated!'], 200);
    }
}
