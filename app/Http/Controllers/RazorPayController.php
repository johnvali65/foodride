<?php

namespace App\Http\Controllers;

use App\CentralLogics\Helpers;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Razorpay\Api\Api;
use App\CentralLogics\CustomerLogic;

class RazorPayController extends Controller
{
    public function payWithRazorpay()
    {
        return view('razor-pay');
    }

    public function payment(Request $request, $order_id)
    {
        info(['........................................................................Razor',$request->all()]);
        $order = Order::where(['id' => $order_id])->first();
        //get API Configuration
        $api = new Api(config('razor.razor_key'), config('razor.razor_secret'));
        //Fetch payment information by razorpay_payment_id
        $payment = $api->payment->fetch($request['razorpay_payment_id']);

        if (count($request->all()) && !empty($request['razorpay_payment_id'])) {
            try {
                // $response = $api->payment->fetch($request['razorpay_payment_id'])->capture(array('amount' => $payment['amount']));
                $order = Order::where(['id' => $payment->description])->first();
                $tr_ref = $request['razorpay_payment_id'];

                $order->transaction_reference = $tr_ref;
                $order->payment_method = 'razor_pay';
                $order->payment_status = 'paid';
                $order->order_status = 'pending';
                // $order->confirmed = now();
                $order->save();
                if($order['wallet_amount'] > 0) {
                    CustomerLogic::create_wallet_transaction($order['user_id'], $order['wallet_amount'], 'order_place', $order->id);
                }
                Helpers::send_order_notification($order);
                $customer = User::find($order->user_id);
                $restaurant = Restaurant::where('id', $order->restaurant_id)->first();
                try {
                    $mobile = $customer['phone'];
                    $message = 'Dear ' . $customer['f_name'] . '

Order has been placed @ ' . date('d M Y, h:i A', strtotime($order->schedule_at)) . '- for store ' . $restaurant['name'] . ' Order ID: ' . $order->id . '

Thanks and Regards
Food Ride';
                    $template_id = 1407169268569030169;
                    Helpers::send_cmoon_sms($mobile, $message, $template_id);
                } catch (\Exception $ex) {
                    info($ex);
                }
            } catch (\Exception $e) {
                info($e);
                Order::
                where('id', $order)
                ->update([
                    'payment_method' => 'razor_pay',
                    'order_status' => 'failed',
                    'failed'=>now(),
                    'updated_at' => now(),
                ]);
                if ($order->callback != null) {
                    return redirect($order->callback . '&status=fail');
                }else{
                    return \redirect()->route('payment-fail');
                }
            }
        }
     //   dd($order->callback);exit;
        if ($order->callback != null) {
            return redirect($order->callback . '&status=success');
        }else{
            return \redirect()->route('payment-success');
        }
    }

    public function wallet_payment(Request $request)
    {
        info(['........................................................................Razor',$request->all()]);
        //get API Configuration
        $api = new Api(config('razor.razor_key'), config('razor.razor_secret'));
        //Fetch payment information by razorpay_payment_id
        $payment = $api->payment->fetch($request['razorpay_payment_id']);

        if (count($request->all()) && !empty($request['razorpay_payment_id'])) {
            try {
                // $response = $api->payment->fetch($request['razorpay_payment_id'])->capture(array('amount' => $payment['amount']));
                $wallet_transaction = CustomerLogic::create_wallet_transaction($payment->description, $payment->amount/100, 'Order place', $request['razorpay_payment_id']);
            } catch (\Exception $e) {
                info($e);
                // Order::
                // where('id', $order)
                // ->update([
                //     'payment_method' => 'razor_pay',
                //     'order_status' => 'failed',
                //     'failed'=>now(),
                //     'updated_at' => now(),
                // ]);
                if (session('wallet_callback') != null) {
                    return redirect(session('wallet_callback') . '&status=fail');
                }else{
                    return \redirect()->route('wallet-payment-fail');
                }
            }
        }
     //   dd($order->callback);exit;
        if (session('wallet_callback') != null) {
            return redirect(session('wallet_callback') . '&status=success');
        }else{
            return \redirect()->route('wallet-payment-success');
        }
    }

}
