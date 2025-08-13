<?php

namespace App\Http\Controllers\api\v1\auth;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\CentralLogics\SMS_module;
use App\Models\BusinessSetting;
use Illuminate\Support\Facades\DB;

class SocialAuthController extends Controller
{
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'unique_id' => 'required',
            'email' => 'required|unique:users,email',
            'phone' => 'required|unique:users,phone',
            'medium' => 'required|in:google,facebook,apple',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $client = new Client();
        $token = $request['token'];
        $email = $request['email'];
        $unique_id = $request['unique_id'];

        try {
            if ($request['medium'] == 'google') {
                $res = $client->request('GET', 'https://www.googleapis.com/oauth2/v1/userinfo?access_token=' . $token);
                $data = json_decode($res->getBody()->getContents(), true);
            } elseif ($request['medium'] == 'facebook') {
                $res = $client->request('GET', 'https://graph.facebook.com/' . $unique_id . '?access_token=' . $token . '&&fields=name,email');
                $data = json_decode($res->getBody()->getContents(), true);
            }
        } catch (\Exception $exception) {
            return response()->json(['error' => 'wrong credential.']);
        }

        if (strcmp($email, $data['email']) === 0) {
            $name = explode(' ', $data['name']);
            if (count($name) > 1) {
                $fast_name = implode(" ", array_slice($name, 0, -1));
                $last_name = end($name);
            } else {
                $fast_name = implode(" ", $name);
                $last_name = '';
            }
            $user = User::where('email', $email)->first();
            if (isset($user) == false) {
                $user = User::create([
                    'f_name' => $fast_name,
                    'l_name' => $last_name,
                    'email' => $email,
                    'phone' => $request->phone,
                    'password' => bcrypt($data['id']),
                    'login_medium' => $request['medium'],
                    'social_id' => $data['id'],
                ]);
            } else {
                return response()->json([
                    'errors' => [
                        ['code' => 'auth-004', 'message' => translate('messages.email_already_exists')]
                    ]
                ], 403);
            }

            $data = [
                'phone' => $user->phone,
                'password' => $user->social_id
            ];
            $customer_verification = BusinessSetting::where('key', 'customer_verification')->first()->value;
            if (auth()->attempt($data)) {
                $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
                if (!auth()->user()->status) {
                    $errors = [];
                    array_push($errors, ['code' => 'auth-003', 'message' => translate('messages.your_account_is_blocked')]);
                    return response()->json([
                        'errors' => $errors
                    ], 403);
                }
                if ($customer_verification && !auth()->user()->is_phone_verified && env('APP_MODE') != 'demo') {
                    $otp = rand(1000, 9999);
                    DB::table('phone_verifications')->updateOrInsert(
                        ['phone' => $request['phone']],
                        [
                            'token' => $otp,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                    $response = SMS_module::send($request['phone'], $otp);
                    if ($response != 'success') {

                        $errors = [];
                        array_push($errors, ['code' => 'otp', 'message' => translate('messages.faield_to_send_sms')]);
                        return response()->json([
                            'errors' => $errors
                        ], 405);
                    }
                }
                return response()->json(['token' => $token, 'is_phone_verified' => auth()->user()->is_phone_verified], 200);
            } else {
                $errors = [];
                array_push($errors, ['code' => 'auth-001', 'message' => 'Unauthorized.']);
                return response()->json([
                    'errors' => $errors
                ], 401);
            }


        }

        return response()->json(['error' => translate('messages.email_does_not_match')]);
    }

    public function social_register(Request $request)
    {
        info('social login request:  ' . $request);
        $validator = Validator::make($request->all(), [
            'device_token' => 'required',
            'unique_id' => 'required',
            // 'email' => 'required|unique:users,email',
            // 'phone' => 'required|unique:users,phone',
            'medium' => 'required|in:google,facebook,apple',
            // 'name' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $device_token = $request['device_token'];

        if ($request->medium != 'apple') {
            if ($request->login_id) {
                $login_id = $request->login_id;
                if (is_numeric($login_id)) {
                    $user = User::where('phone', $request->login_id)->first();
                    $email = '';
                    $phone = $login_id;
                } elseif (filter_var($login_id, FILTER_VALIDATE_EMAIL)) {
                    $user = User::where('email', $request->login_id)->first();
                    $email = $login_id;
                    $phone = '';
                }
            } else {
                $login_id = $request->unique_id;
                $user = User::where('social_id', $request->unique_id)->first();
                $social_id = $login_id;
                $phone = '';
            }
        } else {
            $login_id = $request->unique_id;
            $user = User::where('social_id', $request->unique_id)->first();
            $social_id = $login_id;
            $phone = '';
        }
        if ($request['login_id'] != '') {
            $login_id = $request['login_id'];
            if (is_numeric($login_id)) {
                $email = '';
                $phone = $login_id;
            } elseif (filter_var($login_id, FILTER_VALIDATE_EMAIL)) {
                $email = $login_id;
                $phone = '';
            }
        } else {
            $email = '';
            $phone = '';
        }

        // $email = $request['email'];
        $unique_id = $request['unique_id'];

        if ($request->name != '') {
            $name = explode(' ', $request->name);
            if (count($name) > 1) {
                $fast_name = implode(" ", array_slice($name, 0, -1));
                $last_name = end($name);
            } else {
                $fast_name = implode(" ", $name);
                $last_name = '';
            }
        } else {
            $fast_name = null;
            $last_name = null;
        }
        if (isset($user) == false) {
            if ($email != '') {
                // $user = User::where('email', $email)->first();
                // if (isset($user) == false) {
                $userData = [
                    'f_name' => $fast_name,
                    'l_name' => $last_name,
                    'email' => $email,
                    'phone' => 'n/a',
                    'password' => bcrypt($unique_id),
                    'login_medium' => $request['medium'],
                    'social_id' => $unique_id,
                    'ref_code' => Helpers::generate_referer_code()
                ];

                if ($request->device_type != '' && $request->device_type == 'ios') {
                    $userData['cm_firebase_token_ios'] = $device_token;
                } else {
                    $userData['cm_firebase_token'] = $device_token;
                }

                $user = User::create($userData);
                $user->save();

                // } else {
                //     return response()->json([
                //         'errors' => [
                //             ['code' => 'auth-004', 'message' => translate('messages.email_already_exists')]
                //         ]
                //     ], 403);
                // }
            } else if ($phone != '') {
                // $user = User::where('phone', $phone)->first();
                // if (isset($user) == false) {
                $user = User::create([
                    'f_name' => $fast_name,
                    'l_name' => $last_name,
                    'email' => 'n/a',
                    'phone' => $phone,
                    'password' => bcrypt($unique_id),
                    'login_medium' => $request['medium'],
                    'social_id' => $unique_id,
                    'cm_firebase_token_ios' => $request->device_type == 'ios' ? $device_token : null,
                    'cm_firebase_token' => $request->device_type != 'ios' ? $device_token : null,
                    'ref_code' => Helpers::generate_referer_code()
                ]);
                // } else {
                //     return response()->json([
                //         'errors' => [
                //             ['code' => 'auth-004', 'message' => translate('mobile number already exists')]
                //         ]
                //     ], 403);
                // }
            }

            if ($request['medium'] == 'apple' || $request['medium'] == 'facebook') {
                // if (isset($user) == false) {
                $user = User::create([
                    'f_name' => $fast_name,
                    'l_name' => $last_name,
                    'email' => 'n/a',
                    'phone' => 'n/a',
                    'password' => bcrypt($unique_id),
                    'login_medium' => $request['medium'],
                    'social_id' => $unique_id,
                    'cm_firebase_token_ios' => $request->device_type == 'ios' ? $device_token : null,
                    'cm_firebase_token' => $request->device_type != 'ios' ? $device_token : null,
                    'ref_code' => Helpers::generate_referer_code()
                ]);
                // } else {
                //     return response()->json([
                //         'errors' => [
                //             ['code' => 'auth-004', 'message' => translate('User already exists')]
                //         ]
                //     ], 403);
                // }
            }
        } else {
            User::where('id', $user->id)->update(['login_medium' => $request->medium, 'social_id' => $request->unique_id, 'f_name' => $fast_name, 'l_name' => $last_name, 'password' => bcrypt($request->unique_id)]);
        }
        if ($user->login_medium == 'facebook') {
            $data = [
                'social_id' => $user->social_id,
                'password' => $user->social_id
            ];
        } else if ($user->login_medium != 'apple' && $user->email != '') {
            $data = [
                'email' => $user->email,
                'password' => $user->social_id
            ];
        } else if ($user->login_medium != 'apple' && $user->phone != '') {
            $data = [
                'phone' => $user->phone,
                'password' => $user->social_id
            ];
        } else {
            $data = [
                'social_id' => $user->social_id,
                'password' => $user->social_id
            ];
        }


        $customer_verification = BusinessSetting::where('key', 'customer_verification')->first()->value;
        if (auth()->attempt($data)) {
            $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
            if (!auth()->user()->status) {
                $errors = [];
                array_push($errors, ['code' => 'auth-003', 'message' => translate('messages.your_account_is_blocked')]);
                return response()->json([
                    'errors' => $errors
                ], 403);
            }
            return response()->json(['token' => $token, 'user_data' => $user], 200);
        } else {
            $errors = [];
            array_push($errors, ['code' => 'auth-001', 'message' => 'Unauthorized.']);
            return response()->json([
                'errors' => $errors
            ], 401);
        }

        return response()->json(['error' => translate('messages.email_does_not_match')]);
    }

    public function social_login(Request $request)
    {
        info('social media request:  ' . $request->all());
        $validator = Validator::make($request->all(), [
            'login_id' => 'required'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        if ($request->medium != 'apple') {
            $login_id = $request->login_id;
            if (is_numeric($login_id)) {
                $user = User::where('phone', $request->login_id)->first();
                $email = '';
                $phone = $login_id;
            } elseif (filter_var($login_id, FILTER_VALIDATE_EMAIL)) {
                $user = User::where('email', $request->login_id)->first();
                $email = $login_id;
                $phone = '';
            }
        } else {
            $login_id = $request->login_id;
            $user = User::where('social_id', $request->login_id)->first();
            $social_id = $login_id;
            $phone = '';
        }

        if ($user->login_medium == null) {
            User::where('id', $user->id)->update(['login_medium' => $request->medium, 'social_id' => $request->unique_id, 'password' => bcrypt($request->unique_id)]);
        }

        if (isset($user) == false) {
            return response()->json(['token' => null, 'is_phone_verified' => 0], 200);
        }
        if ($user->email != '') {
            $data = [
                'email' => $user->email,
                'password' => $user->social_id
            ];
        } else {
            $data = [
                'phone' => $user->phone,
                'password' => $user->social_id
            ];
        }
        $customer_verification = BusinessSetting::where('key', 'customer_verification')->first()->value;
        if (auth()->attempt($data)) {
            $token = auth()->user()->createToken('RestaurantCustomerAuth')->accessToken;
            if (!auth()->user()->status) {
                $errors = [];
                array_push($errors, ['code' => 'auth-003', 'message' => translate('messages.your_account_is_blocked')]);
                return response()->json([
                    'errors' => $errors
                ], 403);
            }
            return response()->json(['token' => $token, 'user_data' => $user], 200);
        } else {
            $errors = [];
            array_push($errors, ['code' => 'auth-001', 'message' => 'Unauthorized.']);
            return response()->json([
                'errors' => $errors
            ], 401);
        }

        return response()->json([
            'errors' => [
                ['code' => 'not-found', 'message' => translate('messages.user_not_found')]
            ]
        ], 404);
    }

}
