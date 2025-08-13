<?php

namespace App\Http\Controllers;

use App\CentralLogics\CustomerLogic;
use App\CentralLogics\Helpers;
use App\CentralLogics\OrderLogic;
use App\Models\BusinessSetting;
use App\Models\DeliveryConfig;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;

class PetpoojaCallbackController extends Controller
{
    public function index(Request $request)
    {
        info('data...........' . print_r($request->all(), true));

        if ($request->all()) {
            // Handle the successful response here
            $response1 = $request->all();
            $order = Order::where('id', $response1['orderID'])->first();
            if ($response1['status'] == '1' || $response1['status'] == '2' || $response1['status'] == '3') {
                $order->order_status = 'confirmed';
                $order->confirmed = now();

                $fcm_token = $order->customer->cm_firebase_token;

                // $data_res = [
                //     'title' => translate('messages.order_push_title'),
                //     'description' => 'Order Accepted by the Restaurant',
                //     'order_id' => $order['id'],
                //     'image' => '',
                //     'type' => 'order_status'
                // ];

                // Helpers::send_push_notif_to_device($order->restaurant->vendor->firebase_token, $data_res);

                // Retrieve the delivery configuration based on the 'priority'
                $deliveryConfig = DeliveryConfig::getPriorityChannel();
                if ($deliveryConfig) {
                    if ($deliveryConfig->delivery_channel === 'Pidge') {
                        // Call the Pidge model method
                        return Helpers::sendPidgeNotification($order);
                    } elseif ($deliveryConfig->delivery_channel === 'Owned Delivery Persons') {
                        // Call the Helpers::send_order_notification method
                        Helpers::send_order_notification($order);
                    }
                } else {
                    Helpers::send_order_notification($order);
                }

                // $value = Helpers::order_status_update_message('accepted');
                // try {
                //     if ($value) {
                //         $data = [
                //             'title' => translate('messages.order_push_title'),
                //             'description' => $value,
                //             'order_id' => $order['id'],
                //             'image' => '',
                //             'type' => 'order_status'
                //         ];
                //         Helpers::send_push_notif_to_device($fcm_token, $data);
                //     }

                // } catch (\Exception $e) {

                // }

                $order_id = $order['id'];
                $user_id = $order['user_id'];
                $awt_status = $order['order_status'];
                // try {
                //     Helpers::send_order_notification($order);
                    $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status);
                // } catch (\Throwable $th) {
                //     //throw $th;
                // }
            } else if ($response1['status'] == '4') {
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
            } else if ($response1['status'] == '5') {
                // $order->processing_time = explode('-', $order['restaurant']['delivery_time'])[0];
                // $order->order_status = 'processing';
                // $order->processing = now();

                // $order_id = $order['id'];
                // $user_id = $order['user_id'];
                // $awt_status = $order['order_status'];
                // try {
                //     Helpers::send_order_notification($order);
                //     $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status);
                // } catch (\Throwable $th) {
                //     //throw $th;
                // }
                $order->order_status = 'handover';
                $order->processing = now();
                $order->handover = now();

                $order_id = $order['id'];
                $user_id = $order['user_id'];
                $awt_status = $order['order_status'];
                try {
                    Helpers::send_order_notification($order);
                    $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status);
                } catch (\Throwable $th) {
                    //throw $th;
                }
            } else if ($response1['status'] == '10') {
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
            } else if ($response1['status'] == '-1') {
                // Cancel Logic
                $order->order_status = 'canceled';
                if (($order->payment_method == "cash_on_delivery" || $order->payment_status == "unpaid") && $order->wallet_amount != 0) {
                    if (BusinessSetting::where('key', 'wallet_add_refund')->first()->value == 1) {
                        $order->order_status = 'refunded';
                        CustomerLogic::create_wallet_transaction($order->user_id, $order->wallet_amount, 'order_refund', $order->id);
                    }
                }
                if (isset($order->delivered)) {
                    $rt = OrderLogic::refund_order($order);
                }

                if ($order->payment_status == "paid" && BusinessSetting::where('key', 'wallet_add_refund')->first()->value == 1) {
                    $order->order_status = 'refunded';
                    CustomerLogic::create_wallet_transaction($order->user_id, $order->order_amount + $order->restaurant_discount_amount, 'order_refund', $order->id);
                }

                if ($order->delivery_man) {
                    $dm = $order->delivery_man;
                    $dm->current_orders = $dm->current_orders > 1 ? $dm->current_orders - 1 : 0;
                    $dm->save();
                }

                $order[$order->order_status] = now();

                Helpers::send_order_notification($order);
                $order_id = $order['id'];
                $user_id = $order['user_id'];
                $awt_status = $order['order_status'];
                $newAwtPush = Helpers::awt_order_push_cust_notif_to_topic($order_id, $user_id, $awt_status);
            }
            $order->save();
            info('Petpooja-Callback-executed-successfully');

            $response = [
                "success" => "1",
                "message" => "Status Changed Successfully"
            ];

            return response()->json($response);
        }
    }
}
