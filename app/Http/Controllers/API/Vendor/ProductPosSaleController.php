<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Barcode;
use App\Models\CustomerPayment;
use App\Models\ExchangeSaleProduct;
use App\Models\PosSales;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Settings;
use App\Models\VendorInfo;
use App\Services\Vendor\VariantApiService;
use App\Service\Vendor\ProductPosSaleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProductPosSaleController extends Controller {

    public function index() {
        return response()->json( [
            'status'        => 200,
            'product_sales' => ProductPosSaleService::index(),
        ] );
    }

    public function create() {

        $product = Product::where( 'pre_order', '=', '0' )
            ->where( 'qty', '>', 0 )
            ->where( 'vendor_id', vendorId() )
            ->when( request( 'category_id' ), function ( $q, $category ) {
                $q->where( 'category_id', $category );
            } )
            ->when( request( 'brand_id' ), function ( $q, $brand ) {
                $q->where( 'brand_id', $brand );
            } )
            ->when( request( 'search' ), function ( $q, $search ) {
                $q->where( function ( $query ) use ( $search ) {
                    $query->where( 'sku', $search )
                        ->orWhere( 'name', 'like', '%' . $search . '%' );
                } );
            } )
        // ->with('productVariant',function($q){
        //     $q->select('id','product_id','unit_id','size_id','color_id','qty');
        // })
            ->select( 'id', 'category_id', 'brand_id', 'image', 'is_feature', 'name', 'slug', 'sku', DB::raw( 'CASE
        WHEN discount_price IS NULL THEN selling_price
        ELSE discount_price
        END AS selling_price' ) )
            ->orderBy( 'is_feature', 'DESC' )
            ->get();
        $video = Settings::first()->value( 'pos_video_tutorial' );
        return response()->json( [
            'status'   => 200,
            'data'     => VariantApiService::variationApi(),
            'barcode'  => barcode( 10 ),
            'products' => $product,
            'video'    => $video,
        ] );
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

    //----Barcode scan result----
    public function scan( Request $request ) {

        $barcode = Barcode::where( 'barcode', $request->barcode )->first();
        if ( !$barcode ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Product not found or stock out!',
            ] );
        }

        $productVariant = ProductVariant::where( 'id', $barcode->variant_id )
        // ->where('user_id',vendorId())
            ->where( 'qty', '>', 0 )
            ->with( 'product', 'color', 'size', 'unit' )
            ->first();

        if ( !$productVariant ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'Stock out',
            ] );
        }

        return response()->json( [
            'status'  => 200,
            'product' => $productVariant,
        ] );

    }

    public function store( Request $request ) {
        // Validation rules
        $rules = [
            'customer_id'   => 'required|exists:customers,id',
            'barcode'       => 'required', // Add your validation rules for barcode
            'source_id' => 'required|exists:sale_order_resources,id',
            'payment_id'    => 'required|exists:payment_methods,id',
            'paid_amount'   => 'required|numeric|min:0',
            'total_qty'     => 'required|numeric|min:1',
            'total_price'   => 'required|numeric|min:0',
            'due_amount'    => 'required|numeric|min:0',
            'sale_discount' => 'required|numeric|min:0',
            'product_id'    => 'required|array',
            // Add validation rules for other fields as needed
        ];

        // Custom messages for validation errors
        $messages = [
            'customer_id.required' => 'Customer ID is required.',
            'customer_id.exists'   => 'Invalid customer ID.',
            // Add custom error messages for other fields as needed
        ];

        // Validate the request
        $validator = Validator::make( $request->all(), $rules, $messages );

        // Check if the validation fails
        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ] );
        }

        $getmembershipdetails = getmembershipdetails();

        $productecreateqty = $getmembershipdetails?->pos_sale_qty;

        if ( $productecreateqty == null ) {
            return responsejson( 'You do not have permission for pos sale', 'fail' );
        }

        $totalcreatedproduct = PosSales::where( 'vendor_id', vendorId() )->count();

        if ( Auth::user()->is_employee == null && ismembershipexists() != 1 ) {
            return responsejson( 'You do not have a membership', 'fail' );
        }

        if ( Auth::user()->is_employee == null && isactivemembership() != 1 ) {
            return responsejson( 'Membership expired!', 'fail' );
        }

        if ( $productecreateqty <= $totalcreatedproduct ) {
            return responsejson( 'You can not create invoice more than ' . $productecreateqty . '.', 'fail' );
        }

        $sale                 = new PosSales();
        $sale->customer_id    = $request->customer_id;
        $sale->barcode        = $request->barcode;
        $sale->source_id      = $request->source_id;
        $sale->user_id        = Auth::id();
        $sale->sale_date      = $request->sale_date;
        $sale->payment_id     = $request->payment_id;
        $sale->paid_amount    = $request->paid_amount;
        $sale->total_qty      = $request->total_qty;
        $sale->total_price    = $request->total_price;
        $sale->due_amount     = $request->due_amount;
        $sale->sale_discount  = $request->sale_discount;
        $sale->sale_date      = date( 'Y-m-d' );
        $sale->payment_status = $request->total_price <= $request->paid_amount ? 'paid' : 'due';
        $sale->vendor_id      = vendorId();
        $sale->change_amount  = $request->change_amount;
        $sale->save();

        //For product sale details
        $product_ids = $request->product_id;
        $status      = 'normal';
        ProductPosSaleService::productSaleDetails( $product_ids, $sale->id, $status );

        //For variant stock manage
        $variant = $request->all();
        ProductPosSaleService::productVariants( $product_ids, $variant );

        if ( $request->paid_amount > 0 ) {
            $sale['partial_payment'] = 0;
            ProductPosSaleService::customerPayment( $sale );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Product successfully Sale!',
            'sale_id' => $sale->id,
        ] );
    }

    public function show( $id ) {
        return response()->json( [
            'status'        => 200,
            'logo'          => VendorInfo::where( 'vendor_id', vendorId() )->first(),
            'purchase_show' => ProductPosSaleService::show( $id ),
        ] );
    }

    public function exchange( Request $request, $id ) {

        $sale = PosSales::find( $id );
        if ( !$sale ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Product invoice not found.',
            ] );
        }

        foreach ( $sale->saleDetails as $key => $saleDetail ) {
            // Check if the return quantity array has the key and the return quantity is not null or 0
            if ( isset( $request->return_qty[$key] ) && ( $request->return_qty[$key] !== null && $request->return_qty[$key] != 0 ) ) {
                // Check if return qty is greater than 0 and less than or equal to sale qty
                if ( $request->return_qty[$key] > 0 && $request->return_qty[$key] <= $saleDetail->qty ) {
                    // Calculate the returned sub total
                    $returnedSubTotal = $request->return_qty[$key] * $saleDetail->rate;

                    //Update the sub total of the sale detail

                    $saleDetail->sub_total -= $returnedSubTotal;
                    $saleDetail->qty -= $request->return_qty[$key]; // Decrement the sale quantity
                    $saleDetail->save();

                    // Store the return product
                    $returnProduct               = new ExchangeSaleProduct();
                    $returnProduct->pos_sales_id = $sale->id;
                    $returnProduct->product_id   = $saleDetail->product_id;
                    $returnProduct->unit_id      = $saleDetail->unit_id;
                    $returnProduct->size_id      = $saleDetail->size_id;
                    $returnProduct->color_id     = $saleDetail->color_id;
                    $returnProduct->sale_qty     = $saleDetail->qty;
                    $returnProduct->rate         = $saleDetail->rate;
                    $returnProduct->sub_total    = $returnedSubTotal; // Store the returned sub total
                    $returnProduct->remark       = $request->remark[$key];
                    $returnProduct->return_qty   = $request->return_qty[$key];
                    $returnProduct->save();

                    // Update qty for the product variant
                    ProductVariant::updateOrCreate(
                        [
                            'user_id'    => vendorId(),
                            'product_id' => $saleDetail->product_id,
                            'unit_id'    => $saleDetail->unit_id,
                            'size_id'    => $saleDetail->size_id,
                            'color_id'   => $saleDetail->color_id,
                        ],
                        [
                            'qty' => DB::raw( 'qty + ' . $request->return_qty[$key] ),
                        ]
                    );

                    // Update qty for the product
                    $product = Product::find( $saleDetail->product_id );
                    if ( $product ) {
                        $product->qty += $request->return_qty[$key]; // Increase stock
                        $product->save();
                    }

                    // Store return qty for the product
                    if ( $sale ) {
                        $sale->exchange_qty += $request->return_qty[$key]; // Return qty store
                        $sale->exchange_date   = date( 'Y-m-d' ); // Return date store
                        $sale->exchange_amount = $returnedSubTotal; // Return date store
                        $sale->save();
                    }

                    //For product product sale details
                    $product_ids = $request->product_id;
                    $status      = 'exchange';
                    ProductPosSaleService::productSaleDetails( $product_ids, $sale->id, $status );

                    //For variant stock manage
                    $variant = $request->all();
                    ProductPosSaleService::productVariants( $product_ids, $variant );

                } else {
                    return response()->json( [
                        'status'  => 400,
                        'message' => 'Product exchange quantity is invalid !',
                    ] );
                }
            }
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Product successfully exchange !.',
        ] );
    }

    public function addPayment( $id ) {
        $sale = PosSales::find( $id );

        if ( $sale == null ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Invoice not found.',
            ] );
        }

        if ( $sale->payment_status == 'paid' ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'There are no outstanding payments. Thank you!',
            ] );
        }

        if ( $sale->due_amount < request()->amount ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'The amount you entered exceeds the due amount.',
            ] );
        }

        $sale['partial_payment']        = 1;
        $sale['partial_payment_amount'] = request()->amount;
        $sale['payment_method']         = request()->payment_method_id;
        ProductPosSaleService::customerPayment( $sale );

        return response()->json( [
            'status'  => 200,
            'message' => 'Payment successfully complete !',
        ] );
    }

    public function paymentHistory() {
        $userId = Auth::id();

        $payment_histories = CustomerPayment::where( 'user_id', $userId )
            ->when( request( 'customer_id' ), function ( $q, $supplierId ) {
                return $q->where( 'customer_id', $supplierId );
            } )

            ->when( request()->filled( 'search' ), function ( $query ) {
                $search = request()->input( 'search' );
                $query->where( function ( $q ) use ( $search ) {
                    $q->where( 'invoice_no', 'like', '%' . $search . '%' );
                } );
            } )
            ->when( request( 'start_date' ) && request( 'end_date' ), function ( $q ) {
                $startDate = request( 'start_date' );
                $endDate   = request( 'end_date' );
                return $q->whereBetween( 'date', [$startDate, $endDate] );
            } )
            ->select( 'id', 'invoice_no', 'pos_sales_id', 'customer_id', 'date', 'payment_method_id', 'paid_amount' )
            ->latest()
            ->with( ['customer' => function ( $query ) {
                $query->select( 'id', 'customer_name' );
            }] )
            ->with( ['paymentMethod' => function ( $query ) {
                $query->select( 'id', 'payment_method_name' );
            }] )
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'          => 200,
            'payment_history' => $payment_histories,
        ] );
    }

}
