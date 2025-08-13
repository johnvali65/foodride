<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Validator;
use App\Models\AppSetting;
use Laravel\Passport\Token;
use Illuminate\Support\Facades\DB;

class VersionCheckController extends Controller
{

    public function version_check(Request $request)
    {
        info(json_encode($request->all()));
        $validator = Validator::make($request->all(), [
            'app_version' => 'required|max:255',
            'type' => 'required|string',
            'device_type' => 'required'
        ]);
        if ($validator->fails()) {
            return ['status' => 'invalid', 'message' => $validator->errors()->first()];
        }
        if ($request->user_id != '') {
            info('inside if');
            $user = User::find($request->user_id);
            $user->device_model = $request->device_model ? $request->device_model : null;
            $user->android_version = $request->android_version ? $request->android_version : null;
            $user->app_version = $request->app_version ? $request->app_version : null;
            $user->ip_address = $request->ip();
            $user->save();
        }

        $columnPrefix = ($request->device_type === 'android') ? 'android' : 'ios';
        $app_settings = AppSetting::where('type', $request->type)
            ->select("${columnPrefix}_latest_version_no", "${columnPrefix}_latest_version_note", "${columnPrefix}_download_link", "${columnPrefix}_force_update_required")
            ->first();

        if ($app_settings) {
            $version_no = $app_settings->{"${columnPrefix}_latest_version_no"};
            if ($version_no > $request->app_version) {
                $responseData = ['status' => 'valid', 'message' => 'Success', 'data' => $app_settings];
            } else {
                $responseData = ['status' => 'invalid', 'message' => 'You are using the Latest Version'];
            }
        } else {
            $responseData = ['status' => 'invalid', 'message' => 'There is no Data in App Settings'];
        }

        return response()->json($responseData);
    }

    // public function forceLogoutAllUsers(Request $request)
    // {
    //     // Optional: Add admin-only check
    //     // if (!auth()->user() || auth()->user()->role !== 'admin') {
    //     //     return response()->json(['message' => 'Unauthorized'], 403);
    //     // }

    //     // Revoke all access tokens
    //     Token::where('revoked', false)->update(['revoked' => true]);

    //     // Revoke all refresh tokens
    //     DB::table('oauth_refresh_tokens')->update(['revoked' => true]);

    //     // Optional: Clear Firebase tokens (so no push notifications are sent after logout)
    //     DB::table('users')->update([
    //         'cm_firebase_token' => null,
    //         'cm_firebase_token_ios' => null
    //     ]);

    //     return response()->json([
    //         'message' => 'All users have been forcefully logged out.',
    //     ], 200);
    // }
}
