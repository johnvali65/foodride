<?php

namespace App\Http\Controllers\Admin;

use App\Models\Food;
use App\Models\Tax;
use App\Models\User;
use App\Models\Zone;
use App\Models\Order;
use App\Models\Category;
use App\Models\Restaurant;
use App\Models\OrderDetail;
use App\CentralLogics\CustomerLogic;
use Illuminate\Http\Request;
use App\CentralLogics\Helpers;
use App\Scopes\RestaurantScope;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;

class POSController extends Controller
{
    public function index(Request $request)
    {
        $time = Carbon::now()->toTimeString();
        $zone_id = $request->query('zone_id', null);
        $restaurant_id = $request->query('restaurant_id', null);
        $zone = is_numeric($zone_id) ? Zone::findOrFail($zone_id) : null;
        $restaurant_data = Restaurant::active()->where('zone_id', $zone_id)->find($restaurant_id);
        $category = $request->query('category_id', 0);
        $categories = Category::active()->get();
        $keyword = $request->query('keyword', false);
        $key = explode(' ', $keyword);

        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));
            if (!isset($cart['restaurant_id']) || $cart['restaurant_id'] != $restaurant_id) {
                session()->forget('cart');
                session()->forget('address');
            }
        }

        $products = Food::withoutGlobalScope(RestaurantScope::class)->active()->when($category, function ($query) use ($category) {
            $query->whereHas('category', function ($q) use ($category) {
                return $q->whereId($category)->orWhere('parent_id', $category);
            });
        })
            ->where(['restaurant_id' => $restaurant_id])
            ->when(isset($zone), function ($query) use ($zone) {
                return $query->whereIn('restaurant_id', $zone->restaurants->pluck('id'));
            })
            ->when($keyword, function ($query) use ($key) {
                return $query->where(function ($q) use ($key) {
                    foreach ($key as $value) {
                        $q->orWhere('name', 'like', "%{$value}%");
                    }
                });
            })->available($time)
            ->latest()->paginate(10);

        return view('admin-views.pos.index', compact('categories', 'products', 'category', 'keyword', 'restaurant_data', 'zone'));
    }

    public function quick_view(Request $request)
    {
        $product = Food::withoutGlobalScope(RestaurantScope::class)->with('restaurant')->findOrFail($request->product_id);
        // dd($product);
        return response()->json([
            'success' => 1,
            'view' => view('admin-views.pos._quick-view-data', compact('product'))->render(),
        ]);
    }

    public function quick_view_card_item(Request $request)
    {
        $product = Food::withoutGlobalScope(RestaurantScope::class)->findOrFail($request->product_id);
        //dd($product);
        $item_key = $request->item_key;
        $cart_item = session()->get('cart')[$item_key];

        return response()->json([
            'success' => 1,
            'view' => view('admin-views.pos._quick-view-cart-item', compact('product', 'cart_item', 'item_key'))->render(),
        ]);
    }

    public function variant_price(Request $request)
    {
        $product = Food::withoutGlobalScope(RestaurantScope::class)->with('restaurant')->where(['id' => $request->id])->first();
        $str = '';
        $quantity = 0;
        $price = 0;
        $addon_price = 0;

        foreach (json_decode($product->choice_options) as $key => $choice) {
            if ($str != null) {
                $str .= '-' . str_replace(' ', '', $request[$choice->name]);
            } else {
                $str .= str_replace(' ', '', $request[$choice->name]);
            }
        }

        if ($request['addon_id']) {
            foreach ($request['addon_id'] as $id) {
                $addon_price += $request['addon-price' . $id] * $request['addon-quantity' . $id];
            }
        }

        if ($str != null) {
            $count = count(json_decode($product->variations));
            for ($i = 0; $i < $count; $i++) {
                if (json_decode($product->variations)[$i]->type == $str) {
                    $price = json_decode($product->variations)[$i]->price - Helpers::product_discount_calculate($product, json_decode($product->variations)[$i]->price, $product->restaurant);
                }
            }
        } else {
            $price = $product->price - Helpers::product_discount_calculate($product, $product->price, $product->restaurant);
        }

        if ($request['option_id']) {
            // foreach ($request['option_id'] as $id) {
            $price = $request['option-price' . $request['option_id']] * $request['option-quantity' . $request['option_id']];
            // }
        }

        return array('price' => Helpers::format_currency(($price * $request->quantity) + $addon_price));
    }

    public function addToCart(Request $request)
    {
        $product = Food::with('restaurant')->withoutGlobalScope(RestaurantScope::class)->where(['id' => $request->id])->first();
        //dd($product);
        $data = array();
        $data['id'] = $product->id;
        $str = '';
        $variations = [];
        $price = 0;
        $addon_price = 0;
        $option_price = 0;
        //Gets all the choice values of customer choice option and generate a string like Black-S-Cotton
        foreach (json_decode($product->choice_options) as $key => $choice) {
            $data[$choice->name] = $request[$choice->name];
            $variations[$choice->title] = $request[$choice->name];
            if ($str != null) {
                $str .= '-' . str_replace(' ', '', $request[$choice->name]);
            } else {
                $str .= str_replace(' ', '', $request[$choice->name]);
            }
        }
        $data['variations'] = $variations;
        $data['variant'] = $str;
        if ($request->session()->has('cart') && !isset($request->cart_item_key)) {
            if (count($request->session()->get('cart')) > 0) {
                foreach ($request->session()->get('cart') as $key => $cartItem) {
                    if (is_array($cartItem) && $cartItem['id'] == $request['id'] && $cartItem['variant'] == $str) {
                        return response()->json([
                            'data' => 1
                        ]);
                    }
                }
            }
        }
        //Check the string and decreases quantity for the stock
        if ($str != null) {
            $count = count(json_decode($product->variations));
            for ($i = 0; $i < $count; $i++) {
                if (json_decode($product->variations)[$i]->type == $str) {
                    $price = json_decode($product->variations)[$i]->price;
                }
            }
        } else {
            $price = $product->price;
        }

        $data['quantity'] = $request['quantity'];
        $data['price'] = $price;
        $data['name'] = $product->name;
        $data['discount'] = Helpers::product_discount_calculate($product, $price, $product->restaurant);
        $data['image'] = $product->image;
        $data['add_ons'] = [];
        $data['add_on_qtys'] = [];
        $data['options'] = [];
        $data['option_qtys'] = [];

        if ($request['addon_id']) {
            foreach ($request['addon_id'] as $id) {
                $addon_price += $request['addon-price' . $id] * $request['addon-quantity' . $id];
                $data['add_on_qtys'][] = $request['addon-quantity' . $id];
            }
            $data['add_ons'] = $request['addon_id'];
        }

        $data['addon_price'] = $addon_price;

        if ($request['option_id']) {
            // foreach ($request['option_id'] as $id) {
            $option_price = $request['option-price' . $request['option_id']] * $request['quantity']; // $request['option-quantity' . $request['option_id']];
            // $data['option_qtys'] = $request['option-quantity' . $request['option_id']];
            $data['option_qtys'] = $request['quantity'];
            // }
            $data['options'] = $request['option_id'];
        }

        $data['option_price'] = $option_price;

        if ($data['option_price'] > 0) {
            $data['price'] = $data['option_price'];
        }

        // For Petpooja Tax Calculations
        $tax_value = 0;
        if ($product->pet_pooja_tax_id) {
            $exploded_taxes = explode(',', $product->pet_pooja_tax_id);
            foreach ($exploded_taxes as $value) {
                $tax_data = Tax::where('tax_id', $value)->first();
                if ($tax_data->tax_type == 'percentage') {
                    $tax_value += (($data['price'] + $data['addon_price']) * $tax_data->tax_percentage) / 100;
                } else {
                    $tax_value += $tax_data->tax_percentage;
                }
            }
            $tax_value = $tax_value * $request['quantity'];
        }
        $data['tax'] = number_format($tax_value, config('round_up_to_digit'));

        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));
            if (isset($request->cart_item_key)) {
                $cart[$request->cart_item_key] = $data;
                $data = 2;
            } else {
                $cart->push($data);
            }
        } else {
            $cart = collect([$data, 'restaurant_id' => $product->restaurant_id]);
            $request->session()->put('cart', $cart);
        }

        return response()->json([
            'data' => $data
        ]);
    }

    public function addDeliveryInfo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'contact_person_name' => 'required',
            'contact_person_number' => 'required',
            'floor' => 'required',
            'road' => 'required',
            'house' => 'required',
            'delivery_fee' => 'required',
            'longitude' => 'required',
            'latitude' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::error_processor($validator)]);
        }

        $address = [
            'contact_person_name' => $request->contact_person_name,
            'contact_person_number' => $request->contact_person_number,
            'address_type' => 'delivery',
            'address' => $request->address,
            'floor' => $request->floor,
            'road' => $request->road,
            'house' => $request->house,
            'delivery_fee' => $request->delivery_fee,
            'longitude' => (string) $request->longitude,
            'latitude' => (string) $request->latitude,
        ];

        $request->session()->put('address', $address);

        return response()->json([
            'data' => $address,
            'view' => view('admin-views.pos._address', compact('address'))->render(),
        ]);
    }

    public function cart_items(Request $request)
    {
        $restaurant_data = Restaurant::find($request->restaurant_id);
        return view('admin-views.pos._cart', compact('restaurant_data'));
    }

    //removes from Cart
    public function removeFromCart(Request $request)
    {
        if ($request->session()->has('cart')) {
            $cart = $request->session()->get('cart', collect([]));
            $cart->forget($request->key);
            $request->session()->put('cart', $cart);
        }

        return response()->json([], 200);
    }

    //updated the quantity for a cart item
    public function updateQuantity(Request $request)
    {
        $cart = $request->session()->get('cart', collect([]));
        $cart = $cart->map(function ($object, $key) use ($request) {
            if ($key == $request->key) {
                $object['quantity'] = $request->quantity;
            }
            return $object;
        });
        $request->session()->put('cart', $cart);
        return response()->json([], 200);
    }

    //empty Cart
    public function emptyCart(Request $request)
    {
        session()->forget('cart');
        session()->forget('address');
        return response()->json([], 200);
    }

    public function update_tax(Request $request)
    {
        $cart = $request->session()->get('cart', collect([]));
        $cart['tax'] = $request->tax;
        $request->session()->put('cart', $cart);
        return back();
    }

    public function update_paid(Request $request)
    {
        $cart = $request->session()->get('cart', collect([]));
        $cart['paid'] = $request->paid;
        $request->session()->put('cart', $cart);
        return back();
    }

    public function update_discount(Request $request)
    {
        $cart = $request->session()->get('cart', collect([]));
        $cart['discount'] = $request->discount;
        $cart['discount_type'] = $request->type;
        $request->session()->put('cart', $cart);
        return back();
    }

    public function get_customers(Request $request)
    {
        $key = explode(' ', $request['q']);
        $data = User::where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('f_name', 'like', "%{$value}%")
                    ->orWhere('l_name', 'like', "%{$value}%")
                    ->orWhere('phone', 'like', "%{$value}%");
            }
        })
            ->limit(8)
            ->get([DB::raw('id, CONCAT(f_name, " ", l_name, " (", phone ,")") as text')]);

        return response()->json($data);
    }

    public function place_order(Request $request)
    {

        if (!$request->user_id) {
            Toastr::error(translate('messages.no_customer_selected'));
            return back();
        }
        if (!$request->type) {
            Toastr::error(translate('No payment method selected'));
            return back();
        }
        if ($request->session()->has('cart')) {
            if (count($request->session()->get('cart')) < 2) {
                Toastr::error(translate('messages.cart_empty_warning'));
                return back();
            }
        } else {
            Toastr::error(translate('messages.cart_empty_warning'));
            return back();
        }
        if ($request->session()->has('address')) {
            $address = $request->session()->get('address');
        } else {
            if (!isset($address['delivery_fee'])) {
                Toastr::error(translate('messages.please_select_a_valid_delivery_location_on_the_map'));
                return back();
            }
            Toastr::error(translate('messages.delivery_information_warning'));
            return back();
        }
        if ($request->type == 'wallet' && Helpers::get_business_settings('wallet_status', false) != 1) {
            Toastr::error(translate('messages.customer_wallet_disable_warning'));
        }
        $restaurant = Restaurant::find($request->restaurant_id);
        $cart = $request->session()->get('cart');
        $total_addon_price = 0;
        $total_options_price = 0;
        $product_price = 0;
        $restaurant_discount_amount = 0;
        $modified_tax = 0;

        $order_details = [];
        $order = new Order();
        $order->id = 100000 + Order::all()->count() + 1;
        if (Order::find($order->id)) {
            $order->id = Order::latest()->first()->id + 1;
        }
        $order->payment_status = $request->type == 'wallet' ? 'paid' : 'unpaid';
        $order->order_status = $request->type == 'wallet' ? 'confirmed' : 'pending';
        $order->pending = now();
        if ($request->type == 'wallet') {
            $order->confirmed = now();
        }
        $order->order_type = 'delivery';
        $order->restaurant_id = $restaurant->id;
        $order->zone_id = $restaurant->zone_id;
        $order->user_id = $request->user_id;
        $order->delivery_charge = isset($address) ? $address['delivery_fee'] : 0;
        $order->original_delivery_charge = isset($address) ? $address['delivery_fee'] : 0;
        $order->delivery_address = isset($address) ? json_encode($address) : null;
        $order->checked = 1;
        $order->schedule_at = now();
        $order->created_at = now();
        $order->updated_at = now();
        $order->otp = rand(1000, 9999);
        $order->awtOrderCartDetails = json_encode($cart);
        // return $order->awtOrderCartDetails;
        $awtOrderCartDetails = [];
        foreach ($cart as $c) {
            if (is_array($c)) {

                $awtOrderCartDetails[] = [
                    'food_id' => $c['id'],
                    'name' => $c['name'],
                    'quantity' => $c['quantity'],
                    'price' => $c['price'],
                    'discount' => $c['discount'],
                    'image' => $c['image'],
                    'add_ons' => $c['add_ons'],
                    'add_on_qtys' => $c['add_on_qtys'],
                    'options' => $c['options'],
                    'option_qtys' => $c['option_qtys'],
                    'addon_price' => $c['addon_price'],
                    'option_price' => $c['option_price'],
                    'tax' => $c['tax'],
                ];

                $product = Food::withoutGlobalScope(RestaurantScope::class)->with('restaurant')->find($c['id']);
                //dd($product);
                if ($product) {
                    if ($c['option_price'] > 0) {
                        $c['price'] = $c['option_price'];
                    }
                    $price = $c['price'] + $c['addon_price'];
                    $product = Helpers::product_data_formatting($product);
                    $product->tax = $product->restaurant->tax;
                    $addon_data = Helpers::calculate_addon_price(\App\Models\AddOn::whereIn('id', $c['add_ons'])->get(), $c['add_on_qtys']);
                    $options_data = Helpers::calculate_options_price($c['options'], $c['option_qtys']);
                    $base_price = $options_data ? $options_data['total_options_price'] : $product->price;

                    $or_d = [
                        'food_id' => $c['id'],
                        'item_campaign_id' => null,
                        'food_details' => json_encode($product),
                        'quantity' => $c['quantity'],
                        'price' => $price,
                        'tax_amount' => round(Helpers::tax_calculate($product, round($price * $c['quantity'], config('round_up_to_digit')) - $c['discount'] * $c['quantity']), config('round_up_to_digit')),
                        'discount_on_food' => Helpers::product_discount_calculate($product, $base_price, $product->restaurant),
                        'discount_type' => 'discount_on_product',
                        'variant' => json_encode($c['variant']),
                        'variation' => json_encode(count($c['variations']) ? Helpers::get_varient($product->variations, $c['variations']) : []),
                        'add_ons' => json_encode($addon_data['addons']),
                        'total_add_on_price' => round($addon_data['total_add_on_price'], config('round_up_to_digit')),
                        'options' => $options_data ? json_encode($options_data['options']) : '[]',
                        'total_options_price' => $options_data ? $options_data['total_options_price'] : 0,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    $total_addon_price += $or_d['total_add_on_price'];
                    $total_options_price += $or_d['total_options_price'];
                    $product_price += $price * $or_d['quantity'];
                    $restaurant_discount_amount += $or_d['discount_on_food'] * $or_d['quantity'];
                    $order_details[] = $or_d;
                    if ($or_d['tax_amount']) {
                        $modified_tax += $or_d['tax_amount'];
                    } else {
                        $modified_tax += Helpers::tax_calculate($product, $price);
                    }
                }
            }
        }

        if (isset($cart['discount'])) {
            $restaurant_discount_amount += $cart['discount_type'] == 'percent' && $cart['discount'] > 0 ? ((($product_price + $total_addon_price + $total_options_price - $restaurant_discount_amount) * $cart['discount']) / 100) : $cart['discount'];
        }

        $total_price = $product_price - $restaurant_discount_amount;
        $tax = isset($cart['tax']) ? $cart['tax'] : $restaurant->tax;
        $total_tax_amount = $modified_tax > 0 ? $modified_tax : (($tax > 0) ? (($total_price * $tax) / 100) : 0);

        try {
            $order->restaurant_discount_amount = $restaurant_discount_amount;
            $order->total_tax_amount = $total_tax_amount;
            $order->order_amount = $total_price + $total_tax_amount + $order->delivery_charge;
            $order->payment_method = $request->type == 'wallet' ? 'wallet' : 'cash_on_delivery';
            $order->adjusment = $order->order_amount;
            if ($request->type == 'wallet') {
                if ($request->user_id) {

                    $customer = User::find($request->user_id);
                    if ($customer->wallet_balance < $order->order_amount) {
                        Toastr::error(translate('messages.insufficient_wallet_balance'));
                        return back();
                    } else {
                        CustomerLogic::create_wallet_transaction($order->user_id, $order->order_amount, 'order_place', $order->id);
                    }
                } else {
                    Toastr::error(translate('messages.no_customer_selected'));
                    return back();
                }
            }
            ;
            $order->awtOrderCartDetails = json_encode($awtOrderCartDetails);
            // return $awtOrderCartDetails;
            $order->save();
            foreach ($order_details as $key => $item) {
                $order_details[$key]['order_id'] = $order->id;
            }
            // return $order_details;
            OrderDetail::insert($order_details);
            Helpers::petPoojaSaveOrder($order->id);
            session()->forget('cart');
            session()->forget('address');
            session(['last_order' => $order->id]);
            Toastr::success(translate('messages.order_placed_successfully'));
            return back();
        } catch (\Exception $e) {
            info($e);
        }
        Toastr::warning(translate('messages.failed_to_place_order'));
        return back();
    }

    public function order_list()
    {
        $orders = Order::with(['customer'])
            ->where('order_type', 'pos')
            ->latest()
            ->paginate(config('default_pagination'));
        //dd($orders);
        return view('admin-views.pos.order.list', compact('orders'));
    }

    public function search(Request $request)
    {
        $key = explode(' ', $request['search']);
        $orders = Order::where(function ($q) use ($key) {
            foreach ($key as $value) {
                $q->orWhere('id', 'like', "%{$value}%")
                    ->orWhere('order_status', 'like', "%{$value}%")
                    ->orWhere('transaction_reference', 'like', "%{$value}%");
            }
        })->pos()->limit(100)->get();
        return response()->json([
            'view' => view('admin-views.pos.order.partials._table', compact('orders'))->render()
        ]);
    }

    public function order_details($id)
    {
        $order = Order::with([
            'details',
            'restaurant' => function ($query) {
                return $query->withCount('orders');
            },
            'customer' => function ($query) {
                return $query->withCount('orders');
            },
            'details.food' => function ($query) {
                return $query->withoutGlobalScope(RestaurantScope::class);
            }
        ])->where(['id' => $id])->first();
        //dd($order);
        if (isset($order)) {
            return view('admin-views.pos.order.order-view', compact('order'));
        } else {
            Toastr::info('No more orders!');
            return back();
        }
    }

    public function generate_invoice($id)
    {
        $order = Order::with([
            'details',
            'restaurant' => function ($query) {
                return $query->withCount('orders');
            },
            'customer' => function ($query) {
                return $query->withCount('orders');
            },
            'details.food' => function ($query) {
                return $query->withoutGlobalScope(RestaurantScope::class);
            }
        ])->where('id', $id)->first();
        return response()->json([
            'success' => 1,
            'view' => view('admin-views.pos.order.invoice', compact('order'))->render(),
        ]);
    }
    public function customer_store(Request $request)
    {
        $request->validate([
            'f_name' => 'required',
            'l_name' => 'required',
            'email' => 'required|email|unique:users',
            'phone' => 'required|unique:users',
        ]);
        User::create([
            'f_name' => $request['f_name'],
            'l_name' => $request['l_name'],
            'email' => $request['email'],
            'phone' => $request['phone'],
            'password' => bcrypt('password')
        ]);
        try {
            if (config('mail.status')) {
                Mail::to($request->email)->send(new \App\Mail\CustomerRegistration($request->f_name . ' ' . $request->l_name, true));
            }
        } catch (\Exception $ex) {
            info($ex);
        }
        Toastr::success(translate('customer_added_successfully'));
        return back();
    }

    public function wallet_balance(Request $request)
    {

        $wallet_amount = User::where('id', $request->id)->value('wallet_balance');
        // $wallet_balance = $wallet_amount->wallet_balance;
        return response()->json([
            'success' => 1,
            'value' => $wallet_amount,
        ]);
    }
}
