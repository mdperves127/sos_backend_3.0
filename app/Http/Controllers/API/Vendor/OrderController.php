<?php

namespace App\Http\Controllers\API\Vendor;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductOrderRequest;
use App\Models\CourierCredential;
use App\Models\Customer;
use App\Models\DeliveryAndPickupAddress;
use App\Models\Order;
use App\Models\OrderDeliveryToCourier;
use App\Models\OrderDetails;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Settings;
use App\Models\User;
use App\Services\PathaoService;
use App\Services\ProductOrderService;
use App\Services\RedxService;
use App\Services\Vendor\VariantApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller {
    //
    function AllOrders() {
        // return auth()->user()->id;
        $orders = Order::searchProduct()
            ->where( 'vendor_id', auth()->user()->id )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name', 'orderDetails'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }
    function pendingOrders() {
        $orders = Order::searchProduct()
            ->where( 'vendor_id', auth()->user()->id )
            ->where( 'status', Status::Pending->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function ProgressOrders() {
        $orders = Order::searchProduct()
            ->where( 'vendor_id', auth()->user()->id )
            ->where( 'status', Status::Progress->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function ProductProcessing() {
        $orders = Order::searchProduct()
            ->where( 'vendor_id', auth()->user()->id )
            ->where( 'status', Status::Processing->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function OrderReady() {
        $orders = Order::searchProduct()
            ->where( 'vendor_id', auth()->user()->id )
            ->where( 'status', Status::Ready->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function orderReturn() {
        $orders = Order::searchProduct()
            ->where( 'vendor_id', auth()->user()->id )
            ->where( 'status', Status::Return ->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function receivedOrders() {
        $orders = Order::searchProduct()
            ->where( 'vendor_id', auth()->user()->id )
            ->where( 'status', 'received' )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function DeliveredOrders() {
        $orders = Order::searchProduct()
            ->where( 'vendor_id', auth()->user()->id )
            ->where( 'status', Status::Delivered->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function CanceldOrders() {
        $orders = Order::searchProduct()
            ->where( 'vendor_id', auth()->user()->id )
            ->where( 'status', Status::Cancel->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    function productorderstatus( ProductOrderRequest $request, $id ) {

        if ( isactivemembership() != 1 ) {
            return responsejson( 'Membership Expire renew Now!' );
        }
        $validatedData = $request->validated();

        return ProductOrderService::orderStatus( $validatedData, $id );

    }

    function orderView( $id ) {
        $allData = Order::where( 'id', $id )->where( 'vendor_id', auth()->user()->id )
            ->with( ['product', 'product.category:id,name', 'product.subcategory:id,name', 'product.brand:id,name', 'affiliator:id,uniqid', 'productrating' => function ( $query ) {
                $query->with( 'affiliate:id,name' );
            }] )
            ->first();

        if ( $allData ) {

            $allData->variants = json_decode( $allData->variants );
            if ( $allData->status == 'pending' || $allData->status == 'hold' || $allData->status == 'cancel' ) {
                $allData->phone = substr( $allData->phone, 0, 4 ) . '.....';
                $allData->email = substr( $allData->email, 0, 3 ) . '....';
            }
            return response()->json( [
                'status'  => 200,
                'message' => $allData,
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'Not found',
            ] );
        }
    }

    function HoldOrders() {
        $orders = Order::searchProduct()
            ->where( 'vendor_id', auth()->user()->id )
            ->where( 'status', Status::Hold->value )
            ->with( ['affiliator:id,name', 'vendor:id,name', 'product:id,name,image'] )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        $orders->map( function ( $order ) {
            $order->variants = json_decode( $order->variants );
            return $order;
        } );

        return response()->json( [
            'status'  => 200,
            'message' => $orders,
        ] );
    }

    public function orderCount() {
        $all       = Order::where( 'vendor_id', auth()->user()->id )->count();
        $hold      = Order::where( 'vendor_id', auth()->user()->id )->where( 'status', 'hold' )->count();
        $pending   = Order::where( 'vendor_id', auth()->user()->id )->where( 'status', 'pending' )->count();
        $received  = Order::where( 'vendor_id', auth()->user()->id )->where( 'status', 'received' )->count();
        $progress  = Order::where( 'vendor_id', auth()->user()->id )->where( 'status', 'progress' )->count();
        $delivered = Order::where( 'vendor_id', auth()->user()->id )->where( 'status', 'delivered' )->count();
        $cancel    = Order::where( 'vendor_id', auth()->user()->id )->where( 'status', 'cancel' )->count();
        return response()->json( [
            'status'    => 200,
            'all'       => $all,
            'hold'      => $hold,
            'pending'   => $pending,
            'progress'  => $progress,
            'received'  => $received,
            'delivered' => $delivered,
            'cancel'    => $cancel,
        ] );
    }

    //-----For Manual Order------//

    public function create() {

        try {
            // $products = Product::where( 'is_affiliate', 0 )->where( 'pre_order', '=', '1' )
            $products = Product::where( 'vendor_id', vendorId() )->when( request( 'category_id' ), function ( $q, $category ) {
                $q->where( 'category_id', $category );
            } )
            // ->when(request('brand_id'), function ($q, $brand) {
            //     $q->where('brand_id', $brand);
            // })
            // ->when(request('search'), function ($q, $search) {
            //     $q->where(function ($query) use ($search) {
            //         $query->where('sku', $search)
            //             ->orWhere('name', 'like', '%' . $search . '%');
            //     });
            // })
                ->select( 'id', 'category_id', 'brand_id', 'image', 'name', 'slug', 'sku', DB::raw( 'CASE
                    WHEN discount_price IS NULL THEN selling_price
                    ELSE discount_price
                    END AS selling_price' ) )
                ->get();

            $deliveryAddress = DeliveryAndPickupAddress::whereStatus( 'active' )->whereType( 'delivery' )->where( 'vendor_id', vendorId() )->get();
            $pickupAddress   = DeliveryAndPickupAddress::whereStatus( 'active' )->whereType( 'pickup' )->where( 'vendor_id', vendorId() )->get();

            //Courier Credential
            $courier = CourierCredential::where( 'vendor_id', vendorId() )->whereStatus( 'active' )->select( 'id', 'courier_name', 'status', 'default' )->get();
            $default = CourierCredential::where( 'vendor_id', vendorId() )->whereDefault( 'yes' )->first();

            if ( isset( $default ) && $default && $default->courier_name == 'pathao' ) {
                $access_token = PathaoService::getToken( $default->api_key, $default->secret_key, $default->client_email, $default->client_password );

                $default = CourierCredential::find( $default->id )->only( 'id', 'courier_name', 'status', 'default' );

                if ( $access_token ) {
                    $cities = PathaoService::cities( $access_token ) ?? 'failed';
                }
            } elseif ( isset( $default ) && $default && $default->courier_name == 'redx' ) {

                $apiKey = courierCredential( vendorId(), 'redx' );

                $areas = RedxService::getArea( $apiKey->api_key );
                $areas = json_decode( $areas, true );
            }

            return response()->json( [
                'status'          => 200,
                'barcode'         => barcode( 10 ),
                'data'            => VariantApiService::variationApi(),
                'product'         => $products,
                'deliveryAddress' => $deliveryAddress,
                'pickupAddress'   => $pickupAddress,
                'courier'         => $courier,
                'default_courier' => $default,
                'cities'          => $cities ?? [],
                'areas'           => $areas ?? [],
            ] );
        } catch ( \Exception $e ) {
            return response()->json( $e->getMessage() );
        }
    }

    public function productSelect( $slug ) {
        $product = Product::where( 'slug', $slug )
            ->where( 'vendor_id', vendorId() )
            ->with( 'productVariant', function ( $q ) {
                $q->select( 'id', 'product_id', 'unit_id', 'size_id', 'color_id', 'qty' )->with( 'product', 'color', 'size', 'unit' );
            } )
            ->select( 'id', 'category_id', 'brand_id', 'name', 'slug', 'sku',
                DB::raw( 'CASE
                WHEN discount_price IS NULL THEN selling_price
                ELSE discount_price
                END AS discount_price' ), 'discount_percentage', 'selling_price' )
            ->first();

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );

    }

    public function customerSelect( $id ) {
        $customer = Customer::where( 'id', $id )
            ->with( 'orders', function ( $q ) {
                $q->with( 'orderDetails' );
                // ->select( 'id', 'qty', 'status', 'product_amount', 'order_id', 'customer_id', 'created_at' );
            } )
            ->select( 'id', 'customer_name', 'phone', 'email', 'address' )
            ->first();

        return response()->json( [
            'status'   => 200,
            'customer' => $customer,
        ] );
    }

    // public function orderStore(Request $request)
    // {
    //     $order = new Order();
    //     $order->vendor_id = Auth::id();
    //     $order->name = $request->customer_name;
    //     $order->phone = $request->phone;
    //     $order->email = $request->email;
    //     $order->address = $request->address;
    //     $order->status = $request->status;
    //     $order->order_id = $request->order_id;
    //     $order->product_amount = $request->total_amount;
    //     $order->qty = $request->total_qty;
    //     $order->customer_id = $request->customer_id;
    //     $order->source_id = $request->source_id;
    //     $order->source_id = $request->source_id;
    //     $order->order_media = $request->order_media;
    //     $order->save();

    //     $product_ids = $request->product_id;

    //     foreach($product_ids as $key=> $product_id)
    //     {
    //         $orderDetails = new OrderDetails();
    //         $orderDetails->order_id = $order->id;
    //         $orderDetails->product_id = $product_id;
    //         $orderDetails->unit_id = $request->unit_id[$key];
    //         $orderDetails->size_id = $request->size_id[$key];
    //         $orderDetails->color_id = $request->color_id[$key];
    //         $orderDetails->qty = $request->qty[$key];
    //         $orderDetails->rate = $request->rate[$key];
    //         $orderDetails->sub_total = $request->sub_total[$key];
    //         $orderDetails->save();

    //         return response()->json([
    //             'status' => 200,
    //             'message' => 'Product successfully ordered!',
    //         ]);
    //     }
    // }

    public function orderStore( Request $request ) {
        // dd($request->all());
        $validator = Validator::make( $request->all(), [
            'customer_name'  => 'required|string',
            'phone'          => 'required|string',
            // 'email'          => 'required_if:affiliate_order ,1|email',
            // 'address'        => 'required_if:affiliate_order ,1|string',
            'order_id'       => 'required|string',
            'product_amount' => 'required|numeric',
            'qty'            => 'required|integer',
            'customer_id'    => 'required|integer',
            'source_id'      => 'required|integer',
            'order_media'    => 'required|string',

            // not need now as we count it form vendor defaul courier
            // =====================*****************====================
            // 'courier_id'     => 'required|integer|required_if:courier_id,!=,null',
            // // 'courier_id'     => 'required|integer|exists:courier_credential,id',

            // 'courier_id'     => ['nullable', 'integer', function ( $attribute, $value, $fail ) use ( $request ) {
            //     // Check if courier_credential exists and is valid for the vendor
            //     $checkCourier = CourierCredential::where( [
            //         'vendor_id' => vendorId(), // Assuming vendor_id is passed in the request
            //         'status' => 'active',
            //     ] )->exists();

            //     // If courier credential is valid, make city_id required
            //     if ( $checkCourier && !$value ) {
            //         return $fail( 'The courier id is required when a valid courier credential is found.' );
            //     }
            // }],
            // =====================*****************====================

            // 'city_id'        => ['nullable', 'integer', function ( $attribute, $value, $fail ) use ( $request ) {
            //     // Check if courier_credential exists and is valid for the vendor
            //     $checkCourier = CourierCredential::where( [
            //         'vendor_id' => vendorId(), // Assuming vendor_id is passed in the request
            //         'status' => 'active',
            //     ] )->exists();

            //     // If courier credential is valid, make city_id required
            //     if ( $checkCourier && !$value ) {
            //         return $fail( 'The city id is required when a valid courier credential is found.' );
            //     }
            // }],
            // 'zone_id'        => ['nullable', 'integer', function ( $attribute, $value, $fail ) use ( $request ) {
            //     $checkCourier = CourierCredential::where( [
            //         'vendor_id' => vendorId(),
            //         'status'    => 'active',
            //     ] )->exists();

            //     if ( $checkCourier && !$value ) {
            //         return $fail( 'The zone id is required when a valid courier credential is found.' );
            //     }
            // }],
            // 'area_id'        => ['nullable', 'integer', function ( $attribute, $value, $fail ) use ( $request ) {
            //     $checkCourier = CourierCredential::where( [
            //         'vendor_id' => vendorId(),
            //         'status'    => 'active',
            //     ] )->exists();

            //     if ( $checkCourier && !$value ) {
            //         return $fail( 'The area id is required when a valid courier credential is found.' );
            //     }
            // }],
            'product_id'     => 'required|array',
            'product_id.*'   => 'required|integer',
            // 'unit_id'        => 'required_if:affiliate_order ,1|array',
            // 'unit_id.*'      => 'required_if:affiliate_order ,1|string',
            // 'size_id'        => 'required_if:affiliate_order ,1|array',
            // 'size_id.*'      => 'required_if:affiliate_order ,1|string',
            // 'color_id'       => 'required_if:affiliate_order ,1|array',
            // 'color_id.*'     => 'required_if:affiliate_order ,1|string',
            'sub_qty'        => 'required|array',
            'sub_qty.*'      => 'required|integer',
            'rate'           => 'required|array',
            'rate.*'         => 'required|numeric',
            'sub_total'      => 'required|array',
            'sub_total.*'    => 'required|numeric',
        ] );

        $courierCredential = CourierCredential::where( ['vendor_id' => vendorId(), 'status' => 'active', 'courier_name' => 'pathao', 'default' => 'yes'] )->exists();
        if ( $courierCredential ) {
            $validator = Validator::make( $request->all(), [
                'city_id' => ['required', 'integer', function ( $attribute, $value, $fail ) use ( $request ) {
                    if ( !$value ) {
                        return $fail( 'For courier delivery select a city from the drop down list' );
                    }
                }],
                'zone_id' => ['required', 'integer', function ( $attribute, $value, $fail ) use ( $request ) {
                    if ( !$value ) {
                        return $fail( 'For courier delivery select a zone from the drop down list' );
                    }
                }],
                'area_id' => ['nullable', 'integer', function ( $attribute, $value, $fail ) use ( $request ) {
                    if ( !$value ) {
                        return $fail( 'For courier delivery select a area from the drop down list' );
                    }
                }],
            ] );
        }

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->errors(),
            ] );
        }

        try {
            DB::beginTransaction();
            $order                  = new Order();
            $order->vendor_id       = Auth::id();
            $order->name            = $request->customer_name;
            $order->phone           = $request->phone;
            $order->email           = $request->email;
            $order->address         = $request->address;
            $order->status          = 'pending';
            $order->last_status     = 'pending';
            $order->order_id        = $request->order_id;
            $order->product_amount  = $request->product_amount;
            $order->qty             = $request->qty;
            $order->customer_id     = $request->customer_id;
            $order->source_id       = $request->source_id;
            $order->delivery_charge = $request->delivery_charge;
            $order->sale_discount   = $request->sale_discount;
            $order->paid_amount     = $request->paid_amount;
            $order->due_amount      = $request->due_amount;
            $order->custom_order    = $request->custom_order;
            $order->order_media     = 'Direct';
            $order->delivery_area   = $request->delivery_area;
            $order->pickup_area     = $request->pickup_area;
            $order->shipping_date   = $request->shipping_date;
            $order->additional_note = $request->additional_note;
            $order->internal_note   = $request->internal_note;
            $order->save();

            $product_ids = $request->product_id;

            foreach ( $product_ids as $key => $product_id ) {
                $orderDetails             = new OrderDetails();
                $orderDetails->order_id   = $order->id;
                $orderDetails->product_id = $product_id;
                $orderDetails->unit_id    = $request->unit_id[$key];
                $orderDetails->size_id    = $request->size_id[$key];
                $orderDetails->color_id   = $request->color_id[$key];
                $orderDetails->sub_qty    = $request->sub_qty[$key];
                $orderDetails->rate       = $request->rate[$key];
                $orderDetails->sub_total  = $request->sub_total[$key];
                $orderDetails->save();

                Product::where( 'id', $product_id )->decrement( 'qty', $request->sub_qty[$key] );
            }

            $processedProductIds = [];

            foreach ( $product_ids as $key => $product_id ) {
                if ( !in_array( $product_id, $processedProductIds ) ) {
                    ProductVariant::where( 'product_id', $product_id )->decrement( 'qty', $request->sub_qty[$key] );
                    $processedProductIds[] = $product_id;
                }
            }

            $checkCourier = CourierCredential::where( ['vendor_id' => vendorId(), 'status' => 'active', 'default' => 'yes'] )->exists();
            if ( $checkCourier ) {
                $isPathao = CourierCredential::where( ['vendor_id' => vendorId(), 'status' => 'active', 'default' => 'yes', 'courier_name' => 'pathao'] )->exists();
                $isRedx   = CourierCredential::where( ['vendor_id' => vendorId(), 'status' => 'active', 'default' => 'yes', 'courier_name' => 'redx',
                ] )->exists();
                OrderDeliveryToCourier::create( [
                    'order_id'            => $order->id,
                    'vendor_id'           => vendorId(),
                    'affiliator_id'       => $order->affiliator_id,
                    'merchant_order_id'   => $order->order_id,
                    'recipient_name'      => $request->customer_name,
                    'recipient_phone'     => $request->phone,
                    'recipient_address'   => $request->address,
                    'courier_id'          => $request->courier_id,
                    'item_weight'         => $request->item_weight,
                    'recipient_city'      => $isPathao ? $request->city_id : null,
                    'recipient_zone'      => $isPathao ? $request->zone_id : null,
                    'recipient_area'      => $isPathao ? $request->area_id : ( $isRedx ? $request->area_id : null ),
                    'area_name'           => $isRedx ? $request->area_name : null,
                    'delivery_type'       => 48,
                    'item_type'           => $request->item_type ?? null,
                    'special_instruction' => $request->special_instruction ?? null,
                    'item_quantity'       => $request->item_quantity,
                    'amount_to_collect'   => $request->due_amount,
                    'item_description'    => $request->item_description,

                ] );

            }

            // $checkCourier = CourierCredential::where( [
            //     'vendor_id' => vendorId(),
            //     'status'    => 'active',
            //     'default' => 'yes'
            // ] )->exists();

            // if ( $checkCourier ) {
            //     OrderDeliveryToCourier::create( [
            //         'order_id'            => $order->id,
            //         'vendor_id'           => vendorId(),
            //         'affiliator_id'       => $order->affiliator_id,
            //         'merchant_order_id'   => $order->order_id,
            //         'recipient_name'      => $request->customer_name,
            //         'recipient_phone'     => $request->phone,
            //         'recipient_address'   => $request->address,
            //         'courier_id'          => $request->courier_id,
            //         'item_weight'         => $request->item_weight,
            //         'recipient_city'      => $request->city_id ?? null,
            //         'recipient_zone'      => $request->zone_id ?? null,
            //         'recipient_area'      => $request->area_id ?? null,
            //         'delivery_type'       => 48,
            //         'item_type'           => $request->item_type ?? null,
            //         'special_instruction' => $request->special_instruction ?? null,
            //         'item_quantity'       => $request->item_quantity,
            //         'amount_to_collect'   => $request->due_amount,
            //         'item_description'    => $request->item_description,

            //     ] );
            // }

            DB::commit();
            return response()->json( [
                'status'  => 200,
                'message' => 'Product successfully ordered!',
            ] );
        } catch ( \Exception $e ) {
            DB::rollBack();
            return response()->json( [
                'status'  => 400,
                'message' => $e->getMessage(),
            ] );
        }
    }

    public function invoiceShow( $id ) {
        $order = Order::where( 'id', $id )->where( 'order_media', 'Direct' )
            ->with( ['OrderDetails:id,order_id,product_id,unit_id,color_id,size_id,sub_qty,rate,sub_total,created_at', 'pickupArea:id,address', 'deliveryArea:id,address'] )
            ->select( 'id', 'vendor_id', 'name', 'phone', 'email', 'address', 'status', 'last_status', 'order_id', 'product_amount', 'qty', 'order_media', 'shipping_date', 'created_at', 'delivery_id', 'delivery_charge', 'paid_amount', 'due_amount', 'sale_discount', 'additional_note', 'internal_note', 'pickup_area', 'delivery_area', 'reason' )
            ->first();

        return response()->json( [
            'status' => 200,
            'logo'   => Settings::first()->logo,
            'order'  => $order,
        ] );
    }

}
