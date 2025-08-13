<?php

namespace App\Http\Controllers;

use App\CentralLogics\Helpers;
use App\Models\BusinessSetting;
use App\Models\DeleteAccountRequest;
use App\Models\DeliveryMan;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        /*$this->middleware('auth');*/
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        //return view('home');
        return view('web.index');
    }

    public function terms_and_conditions()
    {
        $data = self::get_settings('terms_and_conditions');
        return view('terms-and-conditions', compact('data'));
    }

    public function about_us()
    {
        $data = self::get_settings('about_us');
        return view('about-us', compact('data'));
    }

    public function contact_us()
    {
        return view('contact-us');
    }

    public function privacy_policy()
    {
        $data = self::get_settings('privacy_policy');
        return view('privacy-policy', compact('data'));
    }

    public static function get_settings($name)
    {
        $config = null;
        $data = BusinessSetting::where(['key' => $name])->first();
        if (isset($data)) {
            $config = json_decode($data['value'], true);
            if (is_null($config)) {
                $config = $data['value'];
            }
        }
        return $config;
    }

    public function send_delete_request(Request $request)
    {
        $name = $request->input('name');
        $email = $request->input('email');
        $mobile = $request->input('mobile');
        $message = $request->input('message');
        $type = $request->input('type');

        $check_in_deleted_requests = DeleteAccountRequest::where('mobile_number', $mobile)
            ->where('type', $type)
            ->count();
        if ($check_in_deleted_requests > 0) {
            return response()->json(4);
        } else {
            if ($type == 'restaurant') {
                $checkUser = Restaurant::where('phone', $mobile)->count();
                if ($checkUser > 0) {
                    $isDeleted = Restaurant::where('phone', $mobile)
                        ->where('is_deleted', 0)
                        ->count();
                    if ($isDeleted) {
                        $data = [
                            'name' => $name,
                            'mobile_number' => $mobile,
                            'email' => $email,
                            'message' => $message,
                            'type' => $type
                        ];
                    } else {
                        return response()->json(3);
                    }
                } else {
                    return response()->json(2);
                }
            } else if ($type == 'delivery_man') {
                $checkUser = DeliveryMan::where('phone', $mobile)->count();
                if ($checkUser > 0) {
                    $isDeleted = DeliveryMan::where('phone', $mobile)
                        ->where('is_deleted', 0)
                        ->count();
                    if ($isDeleted) {
                        $data = [
                            'name' => $name,
                            'mobile_number' => $mobile,
                            'email' => $email,
                            'message' => $message,
                            'type' => $type
                        ];
                    } else {
                        return response()->json(3);
                    }
                } else {
                    return response()->json(2);
                }
            } else if ($type == 'user') {
                $checkUser = User::where('phone', $mobile)->count();
                if ($checkUser > 0) {
                    $isDeleted = User::where('phone', $mobile)
                        ->where('is_deleted', 0)
                        ->count();
                    if ($isDeleted) {
                        $data = [
                            'name' => $name,
                            'mobile_number' => $mobile,
                            'email' => $email,
                            'message' => $message,
                            'type' => $type
                        ];
                    } else {
                        return response()->json(3);
                    }
                } else {
                    return response()->json(2);
                }
            }

            $insert = DeleteAccountRequest::create($data);
            if ($insert) {
                return response()->json(1);
            } else {
                return response()->json(0);
            }
        }
    }
}
