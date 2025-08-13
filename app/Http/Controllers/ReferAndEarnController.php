<?php

namespace App\Http\Controllers;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use App\CentralLogics\CustomerLogic;
use App\Models\BusinessSetting;

class ReferAndEarnController extends Controller
{
    public function referral_store(Request $request)
    {
        // User::where('id','<>',null)->update(['referred_by' => 0]);
        // return 'success';
        $data = explode('-',$request->referrer_id);
        if(isset($data[1])){
            $user_data = User::where('id', $data[1])->first();
            if($user_data->referred_by == '') {
                $refer_check = BusinessSetting::where('key', 'ref_earning_status')
                                                ->value('value');
                if ($refer_check) {
                    // $referrer_earning = BusinessSetting::where('key', 'ref_earning_exchange_rate')
                    //                             ->value('value');
                    // $referrer_wallet_transaction = CustomerLogic::create_wallet_transaction($data[0], $referrer_earning, 'add_fund', 'Referral Earning');
                    
                    $referee_earning = BusinessSetting::where('key', 'referee_earning_rate')
                                                ->value('value');
                    $referee_wallet_transaction = CustomerLogic::create_wallet_transaction($user_data->id, $referee_earning, 'add_fund', 'Referral Bonus');
                    $user_data->referred_by = $data[0];
                    $user_data->referee_amount = $referee_earning;
                    $user_data->save();
                    
                    return response()->json(array('status' => 'valid', 'message' => 'Added Bonus Amount to Wallet'), 200);
                } else {
                    return response()->json(array('status' => 'invalid', 'message' => 'refer functionality not activated'), 403);
                }
            } else {
                return response()->json(array('status' => 'invalid', 'message' => 'Existing User'), 403);
            }
        } else {
            return response()->json(array('status' => 'valid', 'message' => 'success'), 200);
        }
    }
}
