<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Option;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\Validator;
use App\Scopes\RestaurantScope;
use App\Models\Translation;

class OptionController extends Controller
{
    public function list(Request $request)
    {
        $vendor = $request['vendor'];
        $options = Option::withoutGlobalScope(RestaurantScope::class)->withoutGlobalScope('translate')->with('translations')->where('restaurant_id', $vendor->restaurants[0]->id)->latest()->get();

        return response()->json(Helpers::option_data_formatting($options, true, true, app()->getLocale()), 200);
    }

    public function store(Request $request)
    {
        if (!$request->vendor->restaurants[0]->food_section) {
            return response()->json([
                'errors' => [
                    ['code' => 'unauthorized', 'message' => translate('messages.permission_denied')]
                ]
            ], 403);
        }
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'price' => 'required|numeric',
            // 'rank' => 'required|numeric',
            'translations' => 'array'
        ]);

        $data = $request->translations;

        if (count($data) < 1) {
            $validator->getMessageBag()->add('translations', translate('messages.Name and description in english is required'));
        }

        if ($validator->fails() || count($data) < 1) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $vendor = $request['vendor'];

        $option = new Option();
        $option->name = $data[0]['value'];
        $option->price = $request->price;
        $option->rank = NULL;
        $option->restaurant_id = $vendor->restaurants[0]->id;
        $option->save();

        foreach ($data as $key => $item) {
            Translation::updateOrInsert(
                [
                    'translationable_type' => 'App\Models\option',
                    'translationable_id' => $option->id,
                    'locale' => $item['locale'],
                    'key' => $item['key']
                ],
                ['value' => $item['value']]
            );
        }

        return response()->json(['message' => translate('messages.option_added_successfully')], 200);
    }

    public function update(Request $request)
    {
        if (!$request->vendor->restaurants[0]->food_section) {
            return response()->json([
                'errors' => [
                    ['code' => 'unauthorized', 'message' => translate('messages.permission_denied')]
                ]
            ], 403);
        }
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            'name' => 'required',
            'price' => 'required',
            // 'rank' => 'required',
            'translations' => 'array'
        ]);

        $data = $request->translations;

        if (count($data) < 1) {
            $validator->getMessageBag()->add('translations', translate('messages.Name and description in english is required'));
        }

        if ($validator->fails() || count($data) < 1) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $option = Option::withoutGlobalScope(RestaurantScope::class)->find($request->id);
        $option->name = $data[0]['value'];
        $option->price = $request->price;
        $option->save();

        foreach ($data as $key => $item) {
            Translation::updateOrInsert(
                [
                    'translationable_type' => 'App\Models\option',
                    'translationable_id' => $option->id,
                    'locale' => $item['locale'],
                    'key' => $item['key']
                ],
                ['value' => $item['value']]
            );
        }

        return response()->json(['message' => translate('messages.option_updated_successfully')], 200);
    }

    public function delete(Request $request)
    {
        if (!$request->vendor->restaurants[0]->food_section) {
            return response()->json([
                'errors' => [
                    ['code' => 'unauthorized', 'message' => translate('messages.permission_denied')]
                ]
            ], 403);
        }
        $validator = Validator::make($request->all(), [
            'id' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }
        $option = Option::withoutGlobalScope(RestaurantScope::class)->withoutGlobalScope('translate')->findOrFail($request->id);
        $option->translations()->delete();
        $option->delete();

        return response()->json(['message' => translate('messages.option_deleted_successfully')], 200);
    }

    public function status(Request $request)
    {
        if (!$request->vendor->restaurants[0]->food_section) {
            return response()->json([
                'errors' => [
                    ['code' => 'unauthorized', 'message' => translate('messages.permission_denied')]
                ]
            ], 403);
        }
        $validator = Validator::make($request->all(), [
            'id' => 'required',
            'status' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)], 403);
        }

        $option_data = Option::withoutGlobalScope(RestaurantScope::class)->findOrFail($request->id);
        $option_data->status = $request->status;
        $option_data->save();

        return response()->json(['message' => translate('messages.option_status_updated')], 200);
    }

    public function search(Request $request)
    {

        $vendor = $request['vendor'];
        $limit = $request['limite'] ?? 25;
        $offset = $request['offset'] ?? 1;

        $key = explode(' ', $request['search']);
        $options = Option::withoutGlobalScope(RestaurantScope::class)->whereHas('restaurant', function ($query) use ($vendor) {
            return $query->where('vendor_id', $vendor['id']);
        })->where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('name', 'like', "%{$value}%");
            }
        })->orderBy('name')->paginate($limit, ['*'], 'page', $offset);
        $data = [
            'total_size' => $options->total(),
            'limit' => $limit,
            'offset' => $offset,
            'options' => $options->items()
        ];

        return response()->json([$data], 200);
    }

}
