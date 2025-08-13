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

class WalletController extends Controller
{
    public function wallet_details(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select('wallet_balance')
            ->first();
        if ($user != '') {
            $user->wallet_balance = (string) round($user->wallet_balance);
        }

        $data = [
            'data' => $user
        ];
        return response()->json($data, 200);
    }

    public function transactions(Request $request)
    {
        // $validator = Validator::make($request->all(), [
        //     'limit' => 'required',
        //     'offset' => 'required',
        //     'user_id' => 'required'
        // ]);

        // if ($validator->fails()) {
        //     return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        // }
        $limit = 10;

        $paginator = WalletTransaction::where('user_id', $request->user()->id)->latest()->paginate($limit, ['*'], 'page', $request->offset);
        foreach ($paginator as $value) {
            if
            ($value->transaction_type == 'order_place' || $value->transaction_type == 'deduct_fund_by_admin') {
                $value->amount = $value->debit;
                $value->status = 'debit';
            } else {
                $value->amount = $value->credit;
                $value->status = 'credit';
            }
            $value->transaction_type = preg_replace('/_/', ' ', $value->transaction_type);
            $value->created_at = date('d M Y h:i A', strtotime($value->created_at));
        }
        $data = [
            'total_size' => $paginator->total(),
            'limit' => $limit,
            'offset' => $request->offset,
            'data' => $paginator->items()
        ];
        return response()->json($data, 200);
    }

    public function add_to_wallet(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'exists:users,id',
            'amount' => 'numeric|min:.01',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $wallet_transaction = CustomerLogic::create_wallet_transaction($request->customer_id, $request->amount, 'add_fund', 'Added to the Wallet by User');

        if ($wallet_transaction) {
            $data = [
                'message' => 'Amount added Successfully'
            ];
            return response()->json($data, 200);
        }

        return response()->json([
            'errors' => [
                'message' => translate('messages.failed_to_create_transaction')
            ]
        ], 200);
    }

    public function wallet_enable_check(Request $request)
    {
        $wallet_check = BusinessSetting::where('key', 'wallet_status')
            ->value('value');
        if ($wallet_check == 1) {
            $data = [
                'status' => $wallet_check,
                'message' => 'Wallet Enabled'
            ];
            return response()->json($data, 200);
        } else {
            $data = [
                'status' => $wallet_check,
                'message' => 'Wallet Disabled'
            ];
            return response()->json($data, 200);
        }

    }
}
