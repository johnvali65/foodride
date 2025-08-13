<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\DeliveryChannel;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Validator;

class DeliveryChannelsController extends Controller
{
    public function index()
    {
        $delivery_channels = DeliveryChannel::latest()->paginate(config('default_pagination'));
        return view('admin-views.delivery_channels.index', compact('delivery_channels'));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'channel_name' => 'required|max:191',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }

        $delivery_channel = new DeliveryChannel();
        $delivery_channel->channel_name = $request->channel_name;
        $delivery_channel->save();

        return response()->json([], 200);
    }

    public function edit(DeliveryChannel $channel)
    {
        return view('admin-views.delivery_channels.edit', compact('channel'));
    }

    public function update(Request $request, DeliveryChannel $channel)
    {
        $validator = Validator::make($request->all(), [
            'channel_name' => 'required|max:191',
        ]);


        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }

        $channel->channel_name = $request->channel_name;
        $channel->save();

        return response()->json([], 200);
    }

    public function status(Request $request)
    {
        $channel = DeliveryChannel::findOrFail($request->id);
        $channel->status = $request->status;
        $channel->save();
        Toastr::success(translate('messages.delivery_channel_status_updated'));
        return back();
    }

    public function search(Request $request)
    {
        $key = explode(' ', $request['search']);
        $delivery_channels = DeliveryChannel::where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('channel_name', 'like', "%{$value}%");
            }
        })->limit(50)->get();
        return response()->json([
            'view' => view('admin-views.delivery_channels.partials._table', compact('delivery_channels'))->render(),
            'count' => $delivery_channels->count()
        ]);
    }
}
