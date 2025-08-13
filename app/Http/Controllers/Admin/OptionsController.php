<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Option;
use App\Models\Restaurant;
use App\Models\Translation;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;
use Rap2hpoutre\FastExcel\FastExcel;
use Illuminate\Support\Facades\DB;
use App\Scopes\RestaurantScope;

class OptionsController extends Controller
{
    public function index(Request $request)
    {
        $restaurant_id = $request->query('restaurant_id', 'all');
        $options = Option::withoutGlobalScope(RestaurantScope::class)
            ->when(is_numeric($restaurant_id), function ($query) use ($restaurant_id) {
                return $query->where('restaurant_id', $restaurant_id);
            })
            ->orderBy('name')->paginate(config('default_pagination'));
        $restaurant = $restaurant_id != 'all' ? Restaurant::findOrFail($restaurant_id) : null;
        return view('admin-views.options.index', compact('options', 'restaurant'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name.*' => 'max:191',
            'name' => 'array|required',
            'restaurant_id' => 'required',
            'price' => 'required|numeric|between:0,999999999999.99',
        ], [
            'name.required' => translate('messages.Name is required!'),
            'restaurant_id.required' => translate('messages.please_select_restaurant'),
        ]);

        $option = new Option();
        $option->name = $request->name[array_search('en', $request->lang)];
        $option->price = $request->price;
        $option->restaurant_id = $request->restaurant_id;
        $option->save();

        $data = [];
        foreach ($request->lang as $index => $key) {
            if ($request->name[$index] && $key != 'en') {
                array_push($data, array(
                    'translationable_type' => 'App\Models\AddOn',
                    'translationable_id' => $option->id,
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
        Toastr::success(translate('messages.option_added_successfully'));
        return back();
    }

    public function edit($id)
    {
        $option = Option::withoutGlobalScope(RestaurantScope::class)->withoutGlobalScope('translate')->findOrFail($id);
        return view('admin-views.options.edit', compact('option'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|max:191',
            'restaurant_id' => 'required',
            'price' => 'required|numeric|between:0,999999999999.99',
        ], [
            'name.required' => translate('messages.Name is required!'),
            'restaurant_id.required' => translate('messages.please_select_restaurant'),
        ]);

        $option = Option::withoutGlobalScope(RestaurantScope::class)->find($id);
        $option->name = $request->name[array_search('en', $request->lang)];
        $option->price = $request->price;
        $option->restaurant_id = $request->restaurant_id;
        $option->save();

        foreach ($request->lang as $index => $key) {
            if ($request->name[$index] && $key != 'en') {
                Translation::updateOrInsert(
                    [
                        'translationable_type' => 'App\Models\AddOn',
                        'translationable_id' => $option->id,
                        'locale' => $key,
                        'key' => 'name'
                    ],
                    ['value' => $request->name[$index]]
                );
            }
        }

        Toastr::success(translate('messages.option_updated_successfully'));
        return redirect(route('admin.options.add-new'));
    }

    public function delete(Request $request)
    {
        $option = Option::withoutGlobalScope(RestaurantScope::class)->find($request->id);
        $option->delete();
        Toastr::success(translate('messages.option_deleted_successfully'));
        return back();
    }

    public function status($option, Request $request)
    {
        $option_data = Option::withoutGlobalScope(RestaurantScope::class)->find($option);
        $option_data->status = $request->status;
        $option_data->save();
        Toastr::success(translate('messages.option_status_updated'));
        return back();
    }

    public function search(Request $request)
    {
        $key = explode(' ', $request['search']);
        $options = Option::where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('name', 'like', "%{$value}%");
            }
        })->limit(50)->get();
        return response()->json([
            'view' => view('admin-views.options.partials._table', compact('options'))->render()
        ]);
    }
    public function bulk_import_index()
    {
        return view('admin-views.options.bulk-import');
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
        DB::table('add_ons')->insert($data);
        Toastr::success(translate('messages.addon_imported_successfully', ['count' => count($data)]));
        return back();
    }

    public function bulk_export_index()
    {
        return view('admin-views.options.bulk-export');
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
        $options = Option::when($request['type'] == 'date_wise', function ($query) use ($request) {
            $query->whereBetween('created_at', [$request['from_date'] . ' 00:00:00', $request['to_date'] . ' 23:59:59']);
        })
            ->when($request['type'] == 'id_wise', function ($query) use ($request) {
                $query->whereBetween('id', [$request['start_id'], $request['end_id']]);
            })
            ->withoutGlobalScope(RestaurantScope::class)->get();
        return (new FastExcel($options))->download('Addons.xlsx');
    }
}
