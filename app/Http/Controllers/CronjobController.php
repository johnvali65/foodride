<?php

namespace App\Http\Controllers;

use App\CentralLogics\CustomerLogic;
use App\CentralLogics\Helpers;
use App\Models\DeliveryConfig;
use App\Models\Food;
use App\Models\Order;
use Carbon\Carbon;
use App\Models\Restaurant;
use App\Models\User;
use DB;
use Razorpay\Api\Api;
use Illuminate\Support\Facades\Mail;

class CronJobController extends Controller
{
    public function razorpay_status_update()
    {
        // Your logic to update Razorpay order status
        $api = new Api(config('razor.razor_key'), config('razor.razor_secret'));

        // Retrieve orders from the database (adjust this based on your model structure)
        $orders = Order::where('order_status', 'failed')
            ->where('payment_status', 'unpaid')
            ->where('payment_method', 'razor_pay')
            ->where('transaction_reference', '<>', null)
            ->whereDate('created_at', Carbon::now()->format('Y-m-d'))
            ->select(
                'id',
                'transaction_reference as razorpay_order_id',
                'user_id',
                'wallet_amount'
            )
            ->get();

        foreach ($orders as $value) {
            // Make API request to Razorpay to get order status
            $razorpayOrder = $api->order->fetch($value->razorpay_order_id);
            // Update the status in the database
            // $razorpayOrderDetails[] = [
            //     'order_id' => $value->id,
            //     'status' => $razorpayOrder->status
            // ];

            if ($razorpayOrder->status == 'paid') {
                $order = Order::where('transaction_reference', $value->razorpay_order_id)->first();
                $order->order_status = "pending";
                $order->payment_status = 'paid';
                $order->pending = now();
                $order->save();

                if ($order->wallet_amount != '') {
                    CustomerLogic::create_wallet_transaction($order->user_id, $order->wallet_amount, 'order_place', $order->id);
                }

                try {
                    $customer = User::where('id', $order->user_id)->first();
                    $restaurant = Restaurant::where('id', $order->restaurant_id)->first();
                    if ($order->order_status == 'pending' || $order->order_status == 'confirmed') {
                        $mobile = $customer['phone'];
                        $message = "Dear " . $customer['f_name'] . "

Order has been placed @ " . date('d M Y, h:i A', strtotime($order->schedule_at)) . "- for store " . $restaurant['name'] . " Order ID: " . $order->id . "

Thanks and Regards
Food Ride";
                        $template_id = 1407169268569030169;
                        Helpers::send_cmoon_sms($mobile, $message, $template_id);
                    }
                } catch (\Exception $ex) {
                    info($ex);
                }

                try {
                    if ($order->order_status == 'pending') {
                        Mail::to($customer['email'])->send(new \App\Mail\OrderPlaced($order->id));
                    }
                } catch (\Exception $ex) {
                    info($ex);
                }

                Helpers::send_order_notification($order);
                Helpers::awt_order_push_cust_notif_to_topic($order->id, $order->user_id, 'pending');
                // Log the status update
                info("Order ID {$order->id} status updated to {$razorpayOrder->status}");
            }

        }
        info('Razorpay order status update completed.');

        // If you need to return something, do it here
        return 'Razorpay order status update completed.';
    }

    public function pidge_status_check()
    {
        $orders_with_pidge_id = Order::where('pidge_task_id', '<>', null)
            ->where('order_status', '<>', 'delivered')
            ->where('order_status', '<>', 'refunded')
            ->where('order_status', '<>', 'canceled')
            ->where('order_status', '<>', 'failed')
            ->pluck('pidge_task_id');
        foreach ($orders_with_pidge_id as $value) {
            Helpers::getPidgeOrderStatus($value);
        }

        return 'success';
    }

    public function petpooja_item_on_check()
    {
        $food_items = Food::whereNotNull('auto_turn_on_time')
            ->whereDate('auto_turn_on_time', Carbon::today())
            ->get();

        foreach ($food_items as $food_item) {
            if (Carbon::now()->format('Y-m-d H:i') === Carbon::parse($food_item->auto_turn_on_time)->format('Y-m-d H:i')) {
                info('Entered into If condition');
                $food_item = Food::find($food_item->id);
                $food_item->status = 1;
                $food_item->auto_turn_on_time = null;
                $food_item->save();
            }
        }

        info('petpooja_item_on_check cron run successfully');
    }

    public function order_priority_check()
    {
        $orders = Order::where('order_status', 'confirmed')
            ->where('deliveryman_notification_sending_time', '!=', NULL)
            ->get();
        foreach ($orders as $order) {
            $existing_partner = DeliveryConfig::where('delivery_channel', $order->delivery_partner)
                ->first();
            $exceed_time = strtotime($order->deliveryman_notification_sending_time) + (60 * $existing_partner->buffer_time);
            if ($exceed_time < time()) {
                $current_priority = $existing_partner->priority + 1;
                $current_delivery_channel = DeliveryConfig::where('priority', $current_priority)->first();
                if ($current_delivery_channel) {
                    $order->delivery_partner = $current_delivery_channel->delivery_channel;
                    $order->deliveryman_notification_sending_time = date('Y-m-d H:i:s');
                    $order->save();
                    if ($current_delivery_channel->delivery_channel === 'Pidge') {
                        // Call the Pidge model method
                        return Helpers::sendPidgeNotification($order);
                    } elseif ($current_delivery_channel->delivery_channel === 'Owned Delivery Persons') {
                        if ($order->pidge_task_id) {
                            Helpers::cancelPidgeOrder($order->pidge_task_id);
                        }
                        // Call the Helpers::send_order_notification method
                        Helpers::send_order_notification($order);
                    }
                }
            }
        }
        return 'success';
    }

    public function pidge_rider_current_location_update()
    {
        $orders_with_pidge_id = Order::where('pidge_task_id', '<>', null)
            ->where('order_status', '<>', 'delivered')
            ->where('order_status', '<>', 'refunded')
            ->where('order_status', '<>', 'canceled')
            ->where('order_status', '<>', 'failed')
            ->pluck('pidge_task_id');
        // return $orders_with_pidge_id;
        $x = [];
        foreach ($orders_with_pidge_id as $value) {
            $x[] = Helpers::getPidgeCurrentLocationUpdate($value);
        }

        return $x;
    }

    public function updateReviewsCount()
    {
        $reviewCounts = DB::table('reviews')
            ->select('food_id', DB::raw('COUNT(*) as total_reviews'))
            ->groupBy('food_id')
            ->get();

        foreach ($reviewCounts as $review) {
            DB::table('food')
                ->where('id', $review->food_id)
                ->update(['reviews_count' => $review->total_reviews]);
        }

        info('Reviews count updated successfully.');
        return 'Reviews count updated successfully.';
    }
}
