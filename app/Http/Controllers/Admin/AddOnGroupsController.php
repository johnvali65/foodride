<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AddonGroup;
use App\Models\Restaurant;
use App\Models\Translation;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Rap2hpoutre\FastExcel\FastExcel;
use Illuminate\Support\Facades\DB;
use App\Scopes\RestaurantScope;

class AddOnGroupsController extends Controller
{
    public function index(Request $request)
    {
        $restaurant_id = $request->query('restaurant_id', 'all');
        $addons = AddonGroup::withoutGlobalScope(RestaurantScope::class)
            ->when(is_numeric($restaurant_id), function ($query) use ($restaurant_id) {
                return $query->where('restaurant_id', $restaurant_id);
            })
            ->orderBy('group_name')->paginate(config('default_pagination'));
        $restaurant = $restaurant_id != 'all' ? Restaurant::findOrFail($restaurant_id) : null;
        return view('admin-views.addon-groups.index', compact('addons', 'restaurant'));
    }

    public function store(Request $request)
    {
        // return $request;
        $request->validate([
            'name.*' => 'max:191',
            'name' => 'array|required',
            'restaurant_id' => 'required'
        ], [
            'name.required' => translate('messages.Group Name is required!'),
            'restaurant_id.required' => translate('messages.please_select_restaurant'),
        ]);

        $addon = new AddonGroup();
        $addon->group_name = $request->name[array_search('en', $request->lang)];
        $addon->restaurant_id = $request->restaurant_id;
        $addon->group_id = 0;
        $addon->status = 1;
        $addon->save();

        $data = [];
        foreach ($request->lang as $index => $key) {
            if ($request->name[$index] && $key != 'en') {
                array_push($data, array(
                    'translationable_type' => 'App\Models\AddonGroup',
                    'translationable_id' => $addon->id,
                    'locale' => $key,
                    'key' => 'name',
                    'value' => $request->name[$index],
                )
                );
            }
        }
        if (count($data)) {
            Translation::insert($data);
        }
        Toastr::success(translate('messages.addon_group_added_successfully'));
        return back();
    }

    public function edit($id)
    {
        $addon = AddonGroup::withoutGlobalScope(RestaurantScope::class)->withoutGlobalScope('translate')->findOrFail($id);
        return view('admin-views.addon-groups.edit', compact('addon'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|max:191',
            'restaurant_id' => 'required',
        ], [
            'name.required' => translate('messages.Group Name is required!'),
            'restaurant_id.required' => translate('messages.please_select_restaurant'),
        ]);

        $addon = AddonGroup::withoutGlobalScope(RestaurantScope::class)->find($id);
        $addon->group_name = $request->name[array_search('en', $request->lang)];
        $addon->restaurant_id = $request->restaurant_id;
        $addon->save();

        foreach ($request->lang as $index => $key) {
            if ($request->name[$index] && $key != 'en') {
                Translation::updateOrInsert(
                    [
                        'translationable_type' => 'App\Models\AddOn',
                        'translationable_id' => $addon->id,
                        'locale' => $key,
                        'key' => 'name'
                    ],
                    ['value' => $request->name[$index]]
                );
            }
        }

        Toastr::success(translate('messages.addon_group_updated_successfully'));
        return redirect(route('admin.addon-groups.add-new'));
    }

    public function delete(Request $request)
    {
        $addon = AddonGroup::withoutGlobalScope(RestaurantScope::class)->find($request->id);
        $addon->delete();
        Toastr::success(translate('messages.addon_group_deleted_successfully'));
        return back();
    }

    public function status($addon, Request $request)
    {
        $addon_data = AddonGroup::withoutGlobalScope(RestaurantScope::class)->find($addon);
        $addon_data->status = $request->status;
        $addon_data->save();
        Toastr::success(translate('messages.addon_group_status_updated'));
        return back();
    }

    public function search(Request $request)
    {
        $key = explode(' ', $request['search']);
        $addons = AddonGroup::where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('name', 'like', "%{$value}%");
            }
        })->limit(50)->get();
        return response()->json([
            'view' => view('admin-views.addon.partials._table', compact('addons'))->render()
        ]);
    }
    public function bulk_import_index()
    {
        return view('admin-views.addon.bulk-import');
    }

    public function bulk_import_data(Request $request)
    {
        try {
            $collections = (new FastExcel)->import($request->file('products_file'));
        } catch (\Exception $exception) {
            Toastr::error(translate('messages.you_have_uploaded_a_wrong_format_file'));
            return back();
        }

        $data = [];
        foreach ($collections as $collection) {
            if ($collection['name'] === "" && $collection['restaurant_id'] === "") {
                Toastr::error(translate('messages.please_fill_all_required_fields'));
                return back();
            }


            array_push($data, [
                'name' => $collection['name'],
                'price' => $collection['price'],
                'restaurant_id' => $collection['restaurant_id'],
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
        DB::table('addon_groups')->insert($data);
        Toastr::success(translate('messages.addon_imported_successfully', ['count' => count($data)]));
        return back();
    }

    public function bulk_export_index()
    {
        return view('admin-views.addon.bulk-export');
    }

    public function bulk_export_data(Request $request)
    {
        $request->validate([
            'type' => 'required',
            'start_id' => 'required_if:type,id_wise',
            'end_id' => 'required_if:type,id_wise',
            'from_date' => 'required_if:type,date_wise',
            'to_date' => 'required_if:type,date_wise'
        ]);
        $addons = AddonGroup::when($request['type'] == 'date_wise', function ($query) use ($request) {
            $query->whereBetween('created_at', [$request['from_date'] . ' 00:00:00', $request['to_date'] . ' 23:59:59']);
        })
            ->when($request['type'] == 'id_wise', function ($query) use ($request) {
                $query->whereBetween('id', [$request['start_id'], $request['end_id']]);
            })
            ->withoutGlobalScope(RestaurantScope::class)->get();
        return (new FastExcel($addons))->download('Addons.xlsx');
    }
}
