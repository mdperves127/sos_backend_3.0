<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Color;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\ProductPurchaseDetails;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\VendorInfo;
use App\Models\Warehouse;
use App\Service\Vendor\ProductPurchaseService;
use App\Service\Vendor\ProductVariantService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProductPurchaseController extends Controller {

    public function index() {
        return response()->json( [
            'status'           => 200,
            'product_purchase' => ProductPurchaseService::index(),
        ] );
    }

    function create() {

        $data = [
            'supplier'       => Supplier::latest()
                ->where( 'status', 'active' )
                ->where( 'vendor_id', vendorId() )
                ->select( 'id', 'supplier_name', 'business_name' )
                ->get(),
            'unit'           => Unit::where( ['status' => 'active'] )->select( 'id', 'unit_name' )->get(),
            'color'          => Color::where( ['status' => 'active'] )->select( 'id', 'name' )->get(),
            'variation'      => Size::where( ['status' => 'active'] )->select( 'id', 'name' )->get(),
            'payment_method' => PaymentMethod::where( ['status' => 'active'] )->select( 'id', 'payment_method_name', 'acc_no' )->get(),
        ];

        return response()->json( [
            'status'    => 200,
            'data'      => $data,
            'chalan_no' => str_pad( rand( 0, 99999 ), 5, '0', STR_PAD_LEFT ),
        ] );
    }

    /**
     * After a supplier is selected on the purchase page, return every product
     * so the user can pick which items to buy from that supplier.
     */
    function supplierProduct( $supplier_id ) {
        $supplier = Supplier::where( 'id', $supplier_id )
            ->where( 'vendor_id', vendorId() )
            ->where( 'status', 'active' )
            ->select( 'id', 'supplier_name', 'business_name', 'phone' )
            ->first();

        if ( ! $supplier ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Supplier not found or inactive.',
            ], 404 );
        }

        $products = Product::latest()
            ->where( 'vendor_id', vendorId() )
            ->where( 'status', 'active' )
            ->when( request( 'search' ), function ( $query, $search ) {
                $query->where( function ( $q ) use ( $search ) {
                    $q->where( 'name', 'like', '%' . $search . '%' )
                        ->orWhere( 'sku', 'like', '%' . $search . '%' );
                } );
            } )
            ->select( 'id', 'name', 'sku', 'original_price', 'selling_price', 'qty', 'image', 'supplier_id', 'warehouse_id', 'variants' )
            ->get()
            ->map( function ( Product $product ) {
                $variants = is_array( $product->variants ) ? $product->variants : [];
                $product->setAttribute( 'has_variants', $variants !== [] || ProductVariant::where( 'product_id', $product->id )->exists() ? 1 : 0 );
                $product->setAttribute( 'variants', $variants );

                return $product;
            } );

        return response()->json( [
            'status'   => 200,
            'supplier' => $supplier,
            'products' => $products,
        ] );
    }

    public function store( Request $request ) {
        // Normalize multipart/JSON line arrays and string decimals.
        $productIds = array_values( (array) $request->input( 'product_id', [] ) );
        $variantIds = array_values( (array) $request->input( 'variant_id', [] ) );
        $qtys       = array_values( (array) $request->input( 'qty', [] ) );
        $rates      = array_values( (array) $request->input( 'rate', [] ) );
        $subTotals  = array_values( (array) $request->input( 'sub_total', [] ) );

        // Pad variant_id to product_id length when omitted for non-variant rows.
        while ( count( $variantIds ) < count( $productIds ) ) {
            $variantIds[] = null;
        }

        $request->merge( [
            'product_id'       => $productIds,
            'variant_id'       => $variantIds,
            'qty'              => $qtys,
            'rate'             => array_map( fn ( $v ) => ProductVariantService::toDecimal( $v ) ?? $v, $rates ),
            'sub_total'        => array_map( fn ( $v ) => ProductVariantService::toDecimal( $v ) ?? $v, $subTotals ),
            'paid_amount'      => ProductVariantService::toDecimal( $request->input( 'paid_amount' ) ) ?? $request->input( 'paid_amount' ),
            'due_amount'       => ProductVariantService::toDecimal( $request->input( 'due_amount' ) ) ?? $request->input( 'due_amount' ),
            'purchase_discount'=> ProductVariantService::toDecimal( $request->input( 'purchase_discount' ) ) ?? $request->input( 'purchase_discount', 0 ),
            'total_price'      => ProductVariantService::toDecimal( $request->input( 'total_price' ) ) ?? $request->input( 'total_price' ),
            'grand_total'      => ProductVariantService::toDecimal( $request->input( 'grand_total' ) ) ?? $request->input( 'grand_total' ),
            'total_qty'        => ProductVariantService::toDecimal( $request->input( 'total_qty' ) ) ?? $request->input( 'total_qty' ),
        ] );

        $validator = Validator::make( $request->all(), [
            'chalan_no'          => 'required|unique:product_purchases,chalan_no',
            'supplier_id'        => [
                'required',
                'integer',
                function ( $attribute, $value, $fail ) {
                    $exists = Supplier::where( 'id', $value )
                        ->where( 'vendor_id', vendorId() )
                        ->where( 'status', 'active' )
                        ->exists();
                    if ( ! $exists ) {
                        $fail( 'Selected supplier is invalid or inactive.' );
                    }
                },
            ],
            'warehouse_id'       => [
                'required',
                'integer',
                function ( $attribute, $value, $fail ) {
                    $exists = Warehouse::where( 'id', $value )
                        ->where( 'vendor_id', vendorId() )
                        ->where( 'status', 'active' )
                        ->exists();
                    if ( ! $exists ) {
                        $fail( 'Selected warehouse is invalid or inactive.' );
                    }
                },
            ],
            'purchase_date'      => 'required',
            'status'             => 'required|in:ordered,received',
            'payment_id'         => 'required',
            'paid_amount'        => 'required|numeric|min:0',
            'due_amount'         => 'required|numeric|min:0',
            'purchase_discount'  => 'required|numeric|min:0',
            'total_price'        => 'required|numeric|min:0',
            'total_qty'          => 'nullable|numeric|min:0',
            'product_id'         => 'required|array|min:1',
            'product_id.*'       => [
                'required',
                'integer',
                function ( $attribute, $value, $fail ) {
                    $exists = Product::where( 'id', $value )
                        ->where( 'vendor_id', vendorId() )
                        ->exists();
                    if ( ! $exists ) {
                        $fail( 'One or more selected products are invalid.' );
                    }
                },
            ],
            'variant_id'         => 'nullable|array',
            'variant_id.*'       => 'nullable',
            'unit_id'            => 'nullable|array',
            'unit_id.*'          => 'nullable',
            'size_id'            => 'nullable|array',
            'color_id'           => 'nullable|array',
            'rate'               => 'required|array',
            'rate.*'             => 'required|numeric|min:0',
            'sub_total'          => 'required|array',
            'sub_total.*'        => 'required|numeric|min:0',
            'qty'                => 'required|array',
            'qty.*'              => 'required|integer|min:1',
            'note'               => 'nullable|string',
        ], [
            'chalan_no.required'     => 'Chalan number is required.',
            'chalan_no.unique'       => 'Chalan number must be unique.',
            'supplier_id.required'   => 'Select the supplier you purchased from.',
            'warehouse_id.required'  => 'Select the warehouse for this purchase.',
            'purchase_date.required' => 'Purchase date is required.',
            'status.required'        => 'Status is required.',
            'payment_id.required'    => 'Payment ID is required.',
            'product_id.required'    => 'Select at least one product.',
            'qty.*.min'              => 'Quantity must be at least 1 for all details.',
        ] );

        $validator->after( function ( $validator ) use ( $request ) {
            $productIds = (array) $request->input( 'product_id', [] );
            $variantIds = (array) $request->input( 'variant_id', [] );
            $qtys       = (array) $request->input( 'qty', [] );
            $rates      = (array) $request->input( 'rate', [] );
            $subTotals  = (array) $request->input( 'sub_total', [] );
            $count      = count( $productIds );

            if ( count( $qtys ) !== $count || count( $rates ) !== $count || count( $subTotals ) !== $count ) {
                $validator->errors()->add( 'product_id', 'product_id, qty, rate and sub_total arrays must be the same length.' );
            }

            if ( count( $variantIds ) !== $count ) {
                $validator->errors()->add( 'variant_id', 'variant_id array must match product_id length.' );
            }

            $purchaseDate = $this->normalizePurchaseDate( $request->input( 'purchase_date' ) );
            if ( $purchaseDate === null ) {
                $validator->errors()->add( 'purchase_date', 'Purchase date must be a valid date (dd-MM-yyyy).' );
            }

            foreach ( $productIds as $index => $productId ) {
                $product = Product::where( 'id', $productId )
                    ->where( 'vendor_id', vendorId() )
                    ->first();

                if ( ! $product ) {
                    continue;
                }

                $rawVariantId = $variantIds[$index] ?? null;
                $variantId    = ProductVariantService::normalizeNullableId( $rawVariantId );

                if ( ProductPurchaseService::productHasVariants( $product ) ) {
                    if ( ! $variantId ) {
                        $validator->errors()->add(
                            "variant_id.$index",
                            'A variant is required for product #' . $productId . '.'
                        );
                        continue;
                    }

                    $belongs = ProductVariant::where( 'product_id', $productId )
                        ->where( 'id', $variantId )
                        ->exists();

                    if ( ! $belongs ) {
                        $validator->errors()->add(
                            "variant_id.$index",
                            'Variant #' . $variantId . ' does not belong to product #' . $productId . '.'
                        );
                    }
                } elseif ( $variantId ) {
                    $belongs = ProductVariant::where( 'product_id', $productId )
                        ->where( 'id', $variantId )
                        ->exists();

                    if ( ! $belongs ) {
                        $validator->errors()->add(
                            "variant_id.$index",
                            'Variant #' . $variantId . ' does not belong to product #' . $productId . '.'
                        );
                    }
                }
            }
        } );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ], 400 );
        }

        try {
            $purchase = DB::transaction( function () use ( $request ) {
                $productIds   = array_values( (array) $request->product_id );
                $variantIds   = array_values( (array) $request->input( 'variant_id', [] ) );
                $totalQty     = (int) ( $request->total_qty ?: array_sum( array_map( 'intval', (array) $request->qty ) ) );
                $totalPrice   = (float) ( $request->input( 'grand_total', $request->total_price ) );
                $dueAmount    = $request->due_amount;
                if ( $dueAmount === null ) {
                    $dueAmount = max( 0, $totalPrice - (float) $request->paid_amount );
                }
                $purchaseDate = $this->normalizePurchaseDate( $request->purchase_date ) ?? $request->purchase_date;

                $purchase                    = new ProductPurchase();
                $purchase->supplier_id       = $request->supplier_id;
                $purchase->warehouse_id      = $request->warehouse_id;
                $purchase->chalan_no         = $request->chalan_no;
                $purchase->user_id           = Auth::id();
                $purchase->purchase_date     = $purchaseDate;
                $purchase->payment_id        = $request->payment_id;
                $purchase->paid_amount       = $request->paid_amount;
                $purchase->total_qty         = $totalQty;
                $purchase->total_price       = $totalPrice;
                $purchase->due_amount        = $dueAmount;
                $purchase->purchase_discount = $request->purchase_discount ?? 0;
                $purchase->status            = $request->status;
                $purchase->payment_status    = (float) $totalPrice == (float) $request->paid_amount ? 'paid' : 'due';
                $purchase->vendor_id         = vendorId();
                $purchase->note              = $request->note;
                $purchase->save();

                foreach ( $productIds as $key => $product_id ) {
                    $variantId = ProductVariantService::normalizeNullableId( $variantIds[$key] ?? null );
                    $unitId    = ProductVariantService::normalizeNullableId( $request->unit_id[$key] ?? null );
                    $sizeId    = ProductVariantService::normalizeNullableId( $request->size_id[$key] ?? null );
                    $colorId   = ProductVariantService::normalizeNullableId( $request->color_id[$key] ?? null );

                    // Prefer attrs from the selected product_variants row when variant_id is sent.
                    if ( $variantId ) {
                        $dbVariant = ProductVariant::where( 'product_id', $product_id )
                            ->where( 'id', $variantId )
                            ->first();
                        if ( $dbVariant ) {
                            $unitId  = $unitId ?? ProductVariantService::normalizeNullableId( $dbVariant->unit_id );
                            $sizeId  = $sizeId ?? ProductVariantService::normalizeNullableId( $dbVariant->size_id );
                            $colorId = $colorId ?? ProductVariantService::normalizeNullableId( $dbVariant->color_id );
                        }
                    }

                    $purchaseDetails                      = new ProductPurchaseDetails();
                    $purchaseDetails->product_purchase_id = $purchase->id;
                    $purchaseDetails->product_id          = $product_id;
                    $purchaseDetails->variant_id          = $variantId;
                    $purchaseDetails->unit_id             = $unitId;
                    $purchaseDetails->size_id             = $sizeId;
                    $purchaseDetails->color_id            = $colorId;
                    $purchaseDetails->qty                 = (int) $request->qty[$key];
                    $purchaseDetails->rate                = ProductVariantService::toDecimal( $request->rate[$key] ) ?? 0;
                    $purchaseDetails->sub_total           = ProductVariantService::toDecimal( $request->sub_total[$key] ) ?? 0;
                    $purchaseDetails->save();

                    if ( $request->status === 'received' ) {
                        ProductPurchaseService::applyReceivedStock( $purchaseDetails );
                    }
                }

                Product::whereIn( 'id', $productIds )
                    ->where( 'vendor_id', vendorId() )
                    ->update( [
                        'supplier_id'  => $request->supplier_id,
                        'warehouse_id' => $request->warehouse_id,
                    ] );

                if ( (float) $request->paid_amount > 0 ) {
                    $purchase['partial_payment'] = 0;
                    ProductPurchaseService::supplierPayment( $purchase );
                }

                return $purchase;
            } );
        } catch ( \Throwable $e ) {
            return response()->json( [
                'status'  => 500,
                'message' => 'Product purchase failed: ' . $e->getMessage(),
            ], 500 );
        }

        return response()->json( [
            'status'       => 200,
            'message'      => 'Product successfully purchased from supplier.',
            'purchase_id'  => $purchase->id,
            'supplier_id'  => (int) $purchase->supplier_id,
            'warehouse_id' => (int) $purchase->warehouse_id,
        ] );
    }

    public function show( $id ) {
        $purchase = ProductPurchaseService::show( $id );

        if ( ! $purchase ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Product purchase not found.',
            ], 404 );
        }

        return response()->json( [
            'status'        => 200,
            'logo'          => VendorInfo::first(),
            'purchase_show' => $purchase,
        ] );
    }

    public function status( $id ) {
        $purchase = ProductPurchase::where( 'vendor_id', vendorId() )->find( $id );

        if ( ! $purchase ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Product purchase not found.',
            ], 404 );
        }

        if ( $purchase->status == 'received' ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Product already received.',
            ], 400 );
        }

        try {
            DB::transaction( function () use ( $purchase ) {
                $productDetails = ProductPurchaseDetails::where( 'product_purchase_id', $purchase->id )->get();

                foreach ( $productDetails as $productDetail ) {
                    ProductPurchaseService::applyReceivedStock( $productDetail );
                }

                $purchase->status = 'received';
                $purchase->save();
            } );
        } catch ( \Throwable $e ) {
            return response()->json( [
                'status'  => 500,
                'message' => 'Failed to receive purchase: ' . $e->getMessage(),
            ], 500 );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Product successfully received.',
        ] );
    }

    /**
     * Accept dd-MM-yyyy (frontend) or standard Y-m-d / parseable dates.
     */
    private function normalizePurchaseDate( $value ): ?string {
        if ( $value === null || $value === '' ) {
            return null;
        }

        $value = trim( (string) $value );

        foreach ( ['d-m-Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'] as $format ) {
            try {
                $date = Carbon::createFromFormat( $format, $value );
                if ( $date !== false && $date->format( $format ) === $value ) {
                    return $date->format( 'Y-m-d' );
                }
            } catch ( \Throwable $e ) {
                // try next format
            }
        }

        try {
            return Carbon::parse( $value )->format( 'Y-m-d' );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    public function addPayment( $id ) {
        $purchase = ProductPurchase::find( $id );

        if ( $purchase == null ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Invoice not found.',
            ] );
        }

        if ( $purchase->payment_status == 'paid' ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'There are no outstanding payments. Thank you!',
            ] );
        }

        if ( $purchase->due_amount < request()->amount ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'The amount you entered exceeds the due amount.',
            ] );
        }

        $purchase['partial_payment']        = 1;
        $purchase['partial_payment_amount'] = request()->amount;
        $purchase['payment_method']         = request()->payment_method_id;
        ProductPurchaseService::supplierPayment( $purchase );

        return response()->json( [
            'status'  => 200,
            'message' => 'Payment successfully complete !',
        ] );
    }

    public function paymentHistory() {
        $userId = Auth::id();

        $payment_histories = SupplierPayment::where( 'user_id', $userId )
            ->when( request( 'supplier_id' ), function ( $q, $supplierId ) {
                return $q->where( 'supplier_id', $supplierId );
            } )
            ->when( request()->filled( 'search' ), function ( $query ) {
                $search = request()->input( 'search' );
                $query->where( function ( $q ) use ( $search ) {
                    $q->where( 'chalan_no', 'like', '%' . $search . '%' );
                } );
            } )
            ->when( request( 'start_date' ) && request( 'end_date' ), function ( $q ) {
                $startDate = request( 'start_date' );
                $endDate   = request( 'end_date' );
                return $q->whereBetween( 'date', [$startDate, $endDate] );
            } )
            ->select( 'id', 'chalan_no', 'product_purchase_id', 'supplier_id', 'date', 'payment_method_id', 'paid_amount' )
            ->latest()
            ->with( ['supplier' => function ( $query ) {
                $query->select( 'id', 'supplier_name' );
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
