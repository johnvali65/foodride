<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\DeliveryChannel;
use App\Models\DeliveryConfig;
use App\Models\Zone;
use Brian2694\Toastr\Facades\Toastr;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Validator;

class DeliveryChannelSettingsController extends Controller
{
    public function index(Request $request)
    {
        $zones = DB::table('zones')->get();
        $delivery_channel_data = DB::table('delivery_channels')->get();

        foreach ($delivery_channel_data as $val) {
            $data = DB::table('delivery_config')
                ->where('zone_id', $request->input('zone_id'))
                ->where('delivery_channel_id', $val->id)
                ->orderBy('id', 'desc')
                ->first();

            if ($data) {
                $val->priority = $data->priority;
                $val->buffer_time = $data->buffer_time;
                $val->zone_id = $data->zone_id;
            } else {
                $val->priority = 0;
                $val->buffer_time = 0;
                $val->zone_id = $request->input('zone_id');
            }
        }
        $zone_id = $request->input('zone_id');
        // return $delivery_channel_data;

        return view('admin-views.delivery_channel_settings.index', compact('delivery_channel_data', 'zones', 'zone_id'));
    }

    public function store(Request $request)
    {
        $data = $request->input('deliver_channel');
        $zoneId = $request->input('zone_id');

        if ($zoneId == 0) {
            return redirect()->back()->with('error', 'Please Select city');
        }

        foreach ($data as $key => $value) {
            $row = DB::table('delivery_config')
                ->where('zone_id', $zoneId)
                ->where('delivery_channel_id', $value['delivery_channel_id'])
                ->first();
            $delivery_channel_name = DB::table('delivery_channels')->where('id', $value['delivery_channel_id'])->value('channel_name');

            if ($row) {
                DB::table('delivery_config')
                    ->where('zone_id', $zoneId)
                    ->where('delivery_channel_id', $value['delivery_channel_id'])
                    ->update([
                        'priority' => $value['priority'],
                        'buffer_time' => $value['time'],
                        'delivery_channel' => $delivery_channel_name,
                        'updated_at' => Carbon::now()
                    ]);
            } else {
                DB::table('delivery_config')->insert([
                    'zone_id' => $zoneId,
                    'delivery_channel_id' => $value['delivery_channel_id'],
                    'priority' => $value['priority'],
                    'buffer_time' => $value['time'],
                    'delivery_channel' => $delivery_channel_name,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);
            }
        }

        $url = url('admin/delivery-channel-settings?zone_id=' . $zoneId);

        return redirect($url)->with('success', 'Updated successfully');
    }

}
