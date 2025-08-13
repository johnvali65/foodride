<?php

namespace App\Http\Controllers;

use App\CentralLogics\CustomerLogic;
use App\Models\Order;
use App\Models\BusinessSetting;
use App\CentralLogics\Helpers;
use Illuminate\Http\Request;
use Brian2694\Toastr\Facades\Toastr;
use App\Models\User;

class WalletPaymentController extends Controller
{
    /**
     * make_payment Rave payment process
     * @return void
     */
    public function make_payment(Request $request)
    {
        if(BusinessSetting::where('key','wallet_status')->first()->value != 1) return Toastr::error(translate('messages.customer_wallet_disable_warning'));
        $order = Order::with('customer')->where(['id' => $request->order_id, 'user_id'=>$request->user_id])->first();
        if($order->customer->wallet_balance < $order->order_amount)
        {
            Toastr::error(translate('messages.insufficient_balance'));
            return back();
        }
        $transaction = CustomerLogic::create_wallet_transaction($order->user_id, $order->order_amount, 'order_place', $order->id);
        if ($transaction != false) {
            try {
                $order->transaction_reference = $transaction->transaction_id;
                $order->payment_method = 'wallet';
                $order->payment_status = 'paid';
                $order->order_status = 'confirmed';
                $order->confirmed = now();
                $order->save();
                Helpers::send_order_notification($order);
            } catch (\Exception $e) {
                info($e);
            }

            if ($order->callback != null) {
                return redirect($order->callback . '&status=success');
            }else{
                return \redirect()->route('payment-success');
            }
        }
        else{
            $order->payment_method = 'wallet';
            $order->order_status = 'failed';
            $order->failed = now();
            $order->save();
            if ($order->callback != null) {
                return redirect($order->callback . '&status=fail');
            }else{
                return \redirect()->route('payment-fail');
            }
        }

    }
    
    public function payment(Request $request)
    {
        session()->put('customer_id', $request['customer_id']);
        session()->put('wallet_amount', $request->amount);
        session()->put('wallet_callback', $request->callback);

        $customer = User::find($request['customer_id']);

        if (isset($customer)) {
            $data = [
                'name' => $customer['f_name'],
                'email' => $customer['email'],
                'phone' => $customer['phone'],
            ];
            session()->put('data', $data);
            return view('wallet-payment-view');
        }

        return response()->json(['errors' => ['code' => 'wallet-payment', 'message' => 'Data not found']], 403);
    }
    
    public function success()
    {
        $callback = null;

        // $order = Order::where(['id' => session('order_id'), 'user_id'=>session('customer_id')])->first();

        if(session('wallet_callback')) $callback = session('wallet_callback');

        if ($callback != null) {
            return redirect(session('wallet_callback') . '&status=success');
        }
        return response()->json(['message' => 'Payment succeeded'], 200);
    }

    public function fail()
    {
        $callback = null;
        
        // $order = Order::where(['id' => session('order_id'), 'user_id'=>session('customer_id')])->first();
        
        if(session('wallet_callback')) $callback = session('wallet_callback');

        if ($callback != null) {
            return redirect(session('wallet_callback') . '&status=fail');
        }
        return response()->json(['message' => 'Payment failed'], 403);
    }
}
