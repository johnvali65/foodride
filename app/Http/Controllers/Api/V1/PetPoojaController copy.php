<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AddOn;
use App\Models\AddonGroup;
use App\Models\Category;
use App\Models\CategoryTiming;
use App\Models\Food;
use App\Models\Option;
use App\Models\Restaurant;
use App\Models\RestaurantSchedule;
use App\Models\Tax;
use App\Models\Variation;
use App\Models\Vendor;
use App\Models\Zone;
use Grimzy\LaravelMysqlSpatial\Types\Point;

ini_set('memory_limit', '-1');

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PetPoojaController extends Controller
{

    public function __construct()
    {
        header('Content-Type: application/json');
    }
    public function push_menu(Request $request)
    {
        // Decode the JSON request data
        $requestData = json_decode($request->getContent(), true);
        // return $requestData->success;
        $store_ids = [];
        if ($requestData && $requestData['success'] == 1) {
            foreach ($requestData['restaurants'] as $restaurant) {
                $petpoojaStoreId = $restaurant['details']['menusharingcode'];
                $storeRow = Restaurant::where('petpooja_store_id', $petpoojaStoreId)
                    ->where('is_petpooja_linked_store', 1)
                    ->first();

                if ($storeRow) {
                    $store_ids[] = $storeRow->id;
                }
            }
        }

        if (count($store_ids) > 0) {
            $categories = $requestData['categories'];
            $items = $requestData['items'];
            $items_taxes = $requestData['taxes'];
            $addon_groups = $requestData['addongroups'];
            $variations = $requestData['variations'];

            foreach ($store_ids as $store_id) {
                // Handle categories
                if ($categories) {
                    foreach ($categories as $category) {
                        $categoryName = $category['categoryname'];

                        $existingCategory = Category::where('name', $categoryName)
                            ->where('petpooja_category_id', $category['categoryid'])
                            ->where('restaurant_id', $store_id)
                            ->first();

                        if ($existingCategory) {
                            $existingCategory->status = $category['active'];
                            $existingCategory->image = $category['category_image_url'] ?: 'def.png';
                            $existingCategory->save();
                        } else {
                            $newCategory = new Category();
                            $newCategory->name = $category['categoryname'];
                            $newCategory->restaurant_id = $store_id;
                            $newCategory->image = $category['category_image_url'] ?: 'def.png';
                            $newCategory->parent_id = 0;
                            $newCategory->position = 0;
                            $newCategory->priority = 0;
                            $newCategory->status = $category['active'];
                            $newCategory->petpooja_category_id = $category['categoryid'];
                            $newCategory->save();
                        }

                        // Handle category timings
                        if (!empty($category['categorytimings'])) {
                            $categoryId = $existingCategory ? $existingCategory['id'] : $newCategory['id'];
                            CategoryTiming::where('category_id', $categoryId)->delete();

                            $catTimings = json_decode($category['categorytimings'], true); // Convert to array

                            if (is_array($catTimings) && !empty($catTimings)) {
                                foreach ($catTimings as $timing) {
                                    $schedule_day = explode(",", $timing['schedule_day']); // Accessing as array element
                                    $schedule_time_slots = $timing['schedule_time_slots']; // Accessing as array element

                                    foreach ($schedule_day as $day) {
                                        $days_to_save = ($day === 'All') ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] : [$day];

                                        foreach ($days_to_save as $day_to_save) {
                                            foreach ($schedule_time_slots as $time_slot) {
                                                CategoryTiming::create([
                                                    'category_id' => $categoryId,
                                                    'week_name' => $day_to_save,
                                                    'schedule' => $timing['schedule_name'],
                                                    'from_time' => $time_slot['start_time'],
                                                    'to_time' => $time_slot['end_time'],
                                                ]);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                // Handle addon groups
                if ($addon_groups) {
                    foreach ($addon_groups as $value) {
                        $addon_group_id = $value['addongroupid'];

                        $existingAddonGroup = AddonGroup::where('group_id', $addon_group_id)
                            ->where('restaurant_id', $store_id)
                            ->first();

                        if ($existingAddonGroup) {
                            $existingAddonGroup->status = $value['active'];
                            $existingAddonGroup->rank = $value['addongroup_rank'];
                            $existingAddonGroup->save();
                            $addOnGroupId = $existingAddonGroup->id;
                        } else {
                            $newAddonGroup = new AddonGroup();
                            $newAddonGroup->group_id = $addon_group_id;
                            $newAddonGroup->group_name = $value['addongroup_name'];
                            $newAddonGroup->restaurant_id = $store_id;
                            $newAddonGroup->status = $value['active'];
                            $newAddonGroup->rank = $value['addongroup_rank'];
                            $newAddonGroup->save();
                            $addOnGroupId = $newAddonGroup->id;
                        }

                        foreach ($value['addongroupitems'] as $val) {
                            $addon_item_id = $val['addonitemid'];

                            $existingAddon = AddOn::where('item_id', $addon_item_id)
                                ->where('addon_group_id', $addOnGroupId)
                                ->where('restaurant_id', $store_id)
                                ->first();

                            if ($existingAddon) {
                                $existingAddon->status = $val['active'];
                                $existingAddon->rank = $val['addonitem_rank'];
                                $existingAddon->price = $val['addonitem_price'];
                                $existingAddon->save();
                            } else {
                                $newAddon = new AddOn();
                                $newAddon->item_id = $addon_item_id;
                                $newAddon->addon_group_id = $addOnGroupId;
                                $newAddon->name = $val['addonitem_name'];
                                $newAddon->price = $val['addonitem_price'];
                                $newAddon->restaurant_id = $store_id;
                                $newAddon->status = $val['active'];
                                $newAddon->rank = $val['addonitem_rank'];
                                $newAddon->veg = ($val['attributes'] == "1") ? "1" : "0";
                                $newAddon->save();
                            }
                        }
                    }
                }

                // Handle variations
                if ($variations) {
                    foreach ($variations as $value) {
                        $variation_id = $value['variationid'];

                        $existingVariation = Variation::where('variation_id', $variation_id)
                            ->where('restaurant_id', $store_id)
                            ->first();

                        if ($existingVariation) {
                            $existingVariation->status = $value['status'];
                            $existingVariation->save();
                        } else {
                            $newVariation = new Variation();
                            $newVariation->variation_id = $variation_id;
                            $newVariation->name = $value['name'];
                            $newVariation->restaurant_id = $store_id;
                            $newVariation->status = $value['status'];
                            $newVariation->group_name = $value['groupname'];
                            $newVariation->save();
                        }
                    }
                }

                // Handle food item taxes
                if ($items_taxes) {
                    Tax::where('restaurant_id', $store_id)->update(['status' => 0]);

                    foreach ($items_taxes as $taxes) {
                        $tax_type = ($taxes['taxtype'] == 2) ? "fixed" : "percentage";

                        $tax = Tax::where('tax_id', $taxes['taxid'])->first();

                        if (!$tax) {
                            Tax::create([
                                'tax_id' => $taxes['taxid'],
                                'tax_name' => $taxes['taxname'],
                                'tax_type' => $tax_type,
                                'tax_percentage' => $taxes['tax'],
                                'restaurant_id' => $store_id,
                                'status' => $taxes['active']
                            ]);
                        } else {
                            $tax->update([
                                'tax_name' => $taxes['taxname'],
                                'tax_type' => $tax_type,
                                'tax_percentage' => $taxes['tax'],
                                'restaurant_id' => $store_id,
                                'status' => $taxes['active']
                            ]);
                        }
                    }
                } else {
                    Tax::where('restaurant_id', $store_id)->update(['status' => 0]);
                }

                // Handle items
                if ($items) {
                    Food::where('restaurant_id', $store_id)->update(['status' => 0]);

                    foreach ($items as $item) {
                        if ($item['price'] == 0) {
                            $item['price'] = $item['variation'][0]['price'] ?? 0;
                        }

                        $item_name = $item['itemname'];

                        $foodItem = Food::where('name', $item_name)
                            ->where('restaurant_id', $store_id)
                            ->where('petpooja_item_id', $item['itemid'])
                            ->first();

                        $food_type = ($item['item_attributeid'] == "1") ? "1" : "0";

                        $category = [];
                        if ($item['item_categoryid'] != null) {
                            $category_original_id = Category::where('petpooja_category_id', $item['item_categoryid'])->value('id');
                            $category[] = [
                                'id' => $category_original_id,
                                'position' => 1,
                            ];
                        }

                        if ($foodItem) {
                            $foodItem->price = $item['price'] ?: 0;
                            $foodItem->description = $item['itemdescription'];
                            $foodItem->image = $item['item_image_url'];
                            $foodItem->gst_type = $item['gst_type'];
                            $foodItem->pet_pooja_tax_id = $item['item_tax'];
                            $foodItem->status = $item['active'];
                            $foodItem->veg = $food_type;
                            $foodItem->category_id = $category_original_id ?? null;
                            $foodItem->category_ids = json_encode($category);
                        } else {
                            $foodItem = new Food();
                            $foodItem->restaurant_id = $store_id;
                            $foodItem->name = $item['itemname'];
                            $foodItem->price = $item['price'] ?: 0;
                            $foodItem->description = $item['itemdescription'];
                            $foodItem->image = $item['item_image_url'];
                            $foodItem->gst_type = $item['gst_type'];
                            $foodItem->pet_pooja_tax_id = $item['item_tax'];
                            $foodItem->status = $item['active'];
                            $foodItem->veg = $food_type;
                            $foodItem->petpooja_item_id = $item['itemid'];
                            $foodItem->category_id = $category_original_id ?? null;
                            $foodItem->category_ids = json_encode($category);
                        }

                        // Handle addons
                        $addon_ids = [];
                        if ($item['itemallowaddon'] == 1 && !empty($item['addon'])) {
                            // foreach ($item['addon'] as $addon) {
                            //     $addonGroupIds = AddOn::where('addon_group_id', $addon['addon_group_id'])
                            //         ->where('restaurant_id', $store_id)
                            //         ->pluck('id')
                            //         ->toArray();
                            //     $addon_ids = array_merge($addon_ids, $addonGroupIds);
                            // }
                            $add_on_array = [];
                            foreach ($item['addon'] as $addon) {
                                $addonGroup = AddonGroup::where('group_id', $addon['addon_group_id'])
                                    ->where('restaurant_id', $store_id)
                                    ->value('id');
                                if ($addonGroup) {
                                    $add_on_array[] = [
                                        'addon_group_id' => $addonGroup,
                                        'addon_item_selection_min' => $addon['addon_item_selection_min'],
                                        'addon_item_selection_max' => $addon['addon_item_selection_max']
                                    ];
                                }
                            }
                            $addon_ids = array_merge($addon_ids, $add_on_array);
                        }

                        // Handle options (variations)
                        $options_ids = [];
                        if ($item['itemallowvariation'] == 1 && !empty($item['variation'])) {
                            foreach ($item['variation'] as $val) {
                                $optionGroupIds = Option::where('option_id', $val['id'])
                                    ->where('restaurant_id', $store_id)
                                    ->pluck('id')
                                    ->toArray();
                                $options_ids = array_merge($options_ids, $optionGroupIds);

                                $existingOption = Option::where('option_id', $val['id'])
                                    ->where('variation_id', $val['variationid'])
                                    ->where('restaurant_id', $store_id)
                                    ->first();

                                if ($existingOption) {
                                    $existingOption->status = $val['active'];
                                    $existingOption->rank = $val['variationrank'];
                                    $existingOption->save();
                                } else {
                                    $newOption = new Option();
                                    $newOption->option_id = $val['id'];
                                    $newOption->variation_id = $val['variationid'];
                                    $newOption->name = $val['name'];
                                    $newOption->price = $val['price'];
                                    $newOption->restaurant_id = $store_id;
                                    $newOption->status = $val['active'];
                                    $newOption->rank = $val['variationrank'];
                                    $newOption->save();
                                }

                                if (!empty($val['addon'])) {
                                    // foreach ($val['addon'] as $addon) {
                                    //     $addonGroupIds = AddOn::where('addon_group_id', $addon['addon_group_id'])
                                    //         ->where('restaurant_id', $store_id)
                                    //         ->pluck('id')
                                    //         ->toArray();
                                    //     $addon_ids = array_merge($addon_ids, $addonGroupIds);
                                    // }
                                    $add_on_array = [];
                                    foreach ($val['addon'] as $addon) {
                                        $addonGroup = AddonGroup::where('group_id', $addon['addon_group_id'])
                                            ->where('restaurant_id', $store_id)
                                            ->value('id');
                                        if ($addonGroup) {
                                            $add_on_array[] = [
                                                'addon_group_id' => $addonGroup,
                                                'addon_item_selection_min' => $addon['addon_item_selection_min'],
                                                'addon_item_selection_max' => $addon['addon_item_selection_max']
                                            ];
                                        }
                                    }
                                    $addon_ids = array_merge($addon_ids, $add_on_array);
                                }
                            }
                        }
                        $foodItem->add_ons = json_encode([]);
                        $foodItem->petpooja_add_ons = json_encode($addon_ids); // saving the add_ons as json
                        $foodItem->options = json_encode($options_ids); // saving the options as json
                        $foodItem->attributes = json_encode([]); // Assuming this is a placeholder for future use
                        $foodItem->choice_options = json_encode([]); // Assuming this is a placeholder for future use
                        $foodItem->variations = json_encode([]); // Assuming this is a placeholder for future use
                        $foodItem->available_time_starts = '00:00:00';
                        $foodItem->available_time_ends = '23:59:00';

                        $foodItem->save();
                    }
                }
            }
        }

        $response = [
            "success" => "1",
            "message" => "Menu items are successfully listed"
        ];

        return response()->json($response);
    }

    public function get_store_status(Request $request)
    {
        // info('get store status called........' . $request->getContent());
        $requestData = json_decode($request->getContent());

        if ($requestData) {
            $restaurant = Restaurant::where('is_petpooja_linked_store', 1)
                ->where('petpooja_store_id', $requestData->restID)
                ->first();

            $response = [
                "http_code" => 200,
                "status" => "success",
                "store_status" => $restaurant->status,
                "message" => "Store Delivery Status fetched successfully"
            ];
        } else {
            $response = [
                "http_code" => 400,
                "status" => "failed",
                "message" => "Some error for get store status"
            ];
        }
    }

    public function update_store_status(Request $request)
    {
        $postedData = json_decode($request->getContent(), true);
        $petpoojaStoreId = $postedData['restID'] ?? null;
        $storeStatus = $postedData['store_status'] ?? null;

        $storeId = Restaurant::where('petpooja_store_id', $petpoojaStoreId)
            ->where('is_petpooja_linked_store', 1)
            ->value('id');

        if ($storeId) {
            Restaurant::where('id', $storeId)
                ->update(['status' => $storeStatus]);

            $response = ["http_code" => 200, "status" => "success", "message" => "Store Status updated successfully for store restID"];
        } else {
            $response = ["http_code" => 400, "status" => "failed", "message" => "Some error for store status"];
        }

        return response()->json($response);
    }

    public function item_off(Request $request)
    {
        // info('item off called..........' . $request->getContent());

        $data = json_decode($request->getContent());
        $store = Restaurant::where('petpooja_store_id', $data->restID)->first();

        if (!$data && !$store || !isset($data->itemID) || !isset($data->type)) {
            return response()->json(["http_code" => 400, "status" => "failed", "message" => "Invalid request"], 400);
        }

        $itemIDs = $data->itemID;
        $type = $data->type;

        $result = false;

        if ($type == 'item') {
            $result = Food::whereIn('petpooja_item_id', $itemIDs)
                ->update(['status' => $data->inStock ? 1 : 0, 'auto_turn_on_time' => $data->customTurnOnTime]);
        } elseif ($type == 'addon') {
            $result = AddOn::whereIn('item_id', $itemIDs)
                ->update(['status' => $data->inStock ? 1 : 0, 'auto_turn_on_time' => $data->customTurnOnTime]);
        }

        if ($result) {
            return response()->json(["http_code" => 200, "status" => "success", "message" => "Stock status updated successfully"], 200);
        } else {
            return response()->json(["http_code" => 400, "status" => "failed", "message" => "Error updating stock status"], 400);
        }
    }
    public function item_on(Request $request)
    {
        $data = json_decode($request->getContent());
        $store = Restaurant::where('petpooja_store_id', $data->restID)->first();

        if (!$store || !isset($data->itemID) || !isset($data->type)) {
            return response()->json(["http_code" => 400, "status" => "failed", "message" => "Invalid request"], 400);
        }

        $itemIDs = $data->itemID;
        $type = $data->type;

        $result = false;

        if ($type == 'item') {
            $result = Food::whereIn('petpooja_item_id', $itemIDs)
                ->update(['status' => $data->inStock ? 1 : 0]);
        } elseif ($type == 'addon') {
            $result = AddOn::whereIn('item_id', $itemIDs)
                ->update(['status' => $data->inStock ? 1 : 0]);
        }

        if ($result) {
            return response()->json(["http_code" => 200, "status" => "success", "message" => "Stock status updated successfully"], 200);
        } else {
            return response()->json(["http_code" => 400, "status" => "failed", "message" => "Error updating stock status"], 400);
        }
    }

    public function push_store(Request $request)
    {
        // return $request->getContent();
        $requestData = json_decode($request->getContent());
        // return $requestData;
        $timings = $requestData->res_timings;

        $petpooja_store_id = $requestData->outlet_id;
        if (!$petpooja_store_id) {
            return response()->json([
                "success" => 0,
                "message" => "Mapping unsuccessful or Store id not found."
            ]);
        }

        // Handle database operations
        $restaurant = Restaurant::where('petpooja_store_id', $petpooja_store_id)->first();
        if (!$restaurant) {
            // Perform insert task
            $restaurant = new Restaurant();
        }

        if ($requestData->city) {
            $zone = Zone::where('name', $requestData->city)
                ->where('status', 1)
                ->first();
            if ($zone) {
                $zone_id = $zone->id;
                $point = new Point($requestData->lat, $requestData->long);
                $zone_check = Zone::contains('coordinates', $point)->where('id', $zone->id)->first();
                if (!$zone_check) {
                    return response()->json([
                        "success" => 0,
                        "message" => translate('messages.coordinates_out_of_zone')
                    ]);
                }
            } else {
                $zone_id = 0;
            }
        }

        $vendor = Vendor::where('id', $restaurant->vendor_id)->first();
        if (!$vendor) {
            $vendor = new Vendor();
        }
        $nameParts = explode(" ", $requestData->outlet_name);
        $vendor->f_name = $nameParts[0];
        $vendor->l_name = isset($nameParts[1]) ? $nameParts[1] : '';
        $vendor->phone = $requestData->merchant_number;
        $vendor->email = $requestData->email;
        $vendor->password = bcrypt('Foodride@123');
        $vendor->image = $requestData->restaurant_logo;
        $vendor->save();

        $gst = [
            'status' => '1',
            'code' => $requestData->gst_no,
        ];
        $restaurant->name = $requestData->outlet_name;
        $restaurant->phone = $requestData->merchant_number;
        $restaurant->email = $requestData->email;
        $restaurant->logo = $requestData->restaurant_logo;
        $restaurant->latitude = $requestData->lat;
        $restaurant->longitude = $requestData->long;
        $restaurant->address = $requestData->address;
        $restaurant->vendor_id = $vendor->id;
        $restaurant->gst = json_encode($gst);
        $restaurant->zone_id = $zone_id;  // If there is no Match then 0
        $restaurant->awt_fassi = $requestData->fssai_no;
        $restaurant->awt_cuisine = $requestData->cuisines;
        $restaurant->register_through = 'petpooja_api';
        $restaurant->is_petpooja_linked_store = 1;
        $restaurant->petpooja_store_id = $petpooja_store_id;
        $restaurant->save();

        $previous_timings = RestaurantSchedule::where('restaurant_id', $restaurant->id)->delete();
        foreach ($timings as $timing) {
            foreach ($timing->slots as $slot) {
                $schedule = new RestaurantSchedule();
                $schedule->restaurant_id = $restaurant->id;
                $schedule->day = $timing->day;
                $schedule->opening_time = $slot->from;
                $schedule->closing_time = $slot->to;
                $schedule->created_at = now();
                $schedule->updated_at = now();
                $schedule->save();
            }
        }

        return response()->json([
            "success" => 1,
            "message" => "Mapping successful"
        ]);
    }
}
