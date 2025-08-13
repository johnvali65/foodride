<?php

namespace App\Http\Controllers\Api\V1;

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
    public function refer_earn_details()
    {
        $refer_check = BusinessSetting::where('key', 'ref_earning_status')
                                            ->value('value');
            if ($refer_check) {
                $referrer_earning = BusinessSetting::where('key', 'ref_earning_exchange_rate')
                                            ->value('value');
                
                $referee_earning = BusinessSetting::where('key', 'referee_earning_rate')
                                            ->value('value');
                $description = "Get ₹".$referee_earning." cashback when you login through this Link. Hurry! Offer will expire soon";
                $data = array(
                    'referrer_earning' => $referrer_earning,
                    'description' => $description
                    );
                
                return response()->json(array('status' => 'valid', 'message' => 'Details Getting Successfull', 'data' => $data), 200);
            } else {
                return response()->json(array('status' => 'invalid', 'message' => 'refer functionality not activated'), 403);
            }
    }

}
