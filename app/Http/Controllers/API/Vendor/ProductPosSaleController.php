<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Barcode;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\ExchangeSaleProduct;
use App\Models\PaymentMethod;
use App\Models\PosSales;
use App\Models\PosSaleDue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductBundle;
use App\Models\SaleOrderResource;
use App\Models\VendorInfo;
use App\Service\Vendor\PosInstallmentService;
use App\Service\Vendor\ProductPosSaleService;
use App\Service\Vendor\ProductVariantService;
use App\Services\BundleInventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductPosSaleController extends Controller {

    public function index() {
        return response()->json( [
            'status'        => 200,
            'product_sales' => ProductPosSaleService::index(),
        ] );
    }

    public function create() {

        $product = Product::where( 'pre_order', '=', '0' )
            ->where( 'vendor_id', vendorId() )
            ->where( function ( $query ) {
                $query->whereRaw( 'CAST(qty AS SIGNED) > 0' )
                    ->orWhereHas( 'productVariant', function ( $variantQuery ) {
                        $variantQuery->where( 'qty', '>', 0 );
                    } );
            } )
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
            } )->select( 'id', 'category_id', 'brand_id', 'image', 'is_feature', 'name', 'slug', 'sku', DB::raw( 'CASE
        WHEN discount_price IS NULL THEN selling_price
        ELSE discount_price
        END AS selling_price' ) )
            ->orderBy( 'is_feature', 'DESC' )
            ->get();
        // $video          = Settings::first()->value( 'pos_video_tutorial' );
        $variantApiData = [
            'category'        => Category::whereStatus( 'active' )->with( 'subcategory', function ( $q ) {
                $q->select( 'id', 'name', 'category_id' );
            } )
                ->select( 'id', 'name' )->get(),
            'brand'           => Brand::whereStatus( 'active' )->select( 'id', 'name' )->get(),
            'customer'        => Customer::where( 'vendor_id', vendorId() )->where( 'status', 'active' )->select( 'id', 'customer_name', 'phone', 'email', 'address' )->get(),
            'resource'        => SaleOrderResource::latest()->where( 'vendor_id', vendorId() )->where( 'status', 'active' )->select( 'id', 'name' )->get(),
            'payment_methods' => PaymentMethod::where( 'vendor_id', vendorId() )->where( 'status', 'active' )->select( 'id', 'payment_method_name' )->get(),
        ];
        return response()->json( [
            'status'   => 200,
            'data'     => $variantApiData,
            'barcode'  => barcode( 10 ),
            'products' => $product,
            'bundles'  => $this->activeBundlesForPos( request( 'category_id' ), request( 'search' ) ),
            'video'    => 'test.mp4',
        ] );
    }

    public function bundleSelect( $id )
    {
        $bundle = ProductBundle::active()
            ->with( [
                'items.product:id,name,image,sku,selling_price,discount_price,qty',
                'category:id,name',
                'subcategory:id,name',
            ] )
            ->find( $id );

        if ( ! $bundle ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Bundle not found.',
            ], 404 );
        }

        $regular = $bundle->calculateRegularPrice();

        return response()->json( [
            'status' => 200,
            'bundle' => [
                'id'            => $bundle->id,
                'name'          => $bundle->name,
                'type'          => 'bundle',
                'bundle_price'  => (float) $bundle->bundle_price,
                'regular_price' => $regular,
                'category_id'   => $bundle->category_id,
                'items'         => $bundle->items,
            ],
        ] );
    }

    public function productSelect( $slug ) {
        $product = Product::where( 'slug', $slug )
            ->where( 'vendor_id', vendorId() )
            ->first();

        if ( ! $product ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Product not found.',
            ] );
        }

        ProductVariantService::reconcileProduct( $product );
        $product->refresh();

        $product = Product::where( 'id', $product->id )
            ->select( 'id', 'category_id', 'brand_id', 'name', 'slug', 'sku', 'qty',
                DB::raw( 'CASE
                WHEN discount_price IS NULL THEN selling_price
                ELSE discount_price
                END AS discount_price' ), 'discount_percentage', 'selling_price' )
            ->with( [
                'productVariant' => function ( $q ) {
                    $q->select( 'id', 'product_id', 'unit_id', 'size_id', 'color_id', 'qty' )
                        ->with( 'product', 'color', 'size', 'unit' );
                },
            ] )
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
            'customer_id'          => 'required|exists:customers,id',
            'barcode'              => 'required',
            'source_id'            => 'required|exists:sale_order_resources,id',
            'payment_id'           => 'required|exists:payment_methods,id',
            'paid_amount'          => 'required|numeric|min:0',
            'total_qty'            => 'required|numeric|min:1',
            'total_price'          => 'required|numeric|min:0',
            'due_amount'           => 'required|numeric|min:0',
            'sale_discount'        => 'required|numeric|min:0',
            'discount_type'        => 'required|in:flat,percentage',
            'product_id'           => 'nullable|array',
            'bundles'              => 'nullable|array',
            'bundles.*.bundle_id'  => 'required_with:bundles|integer|min:1',
            'bundles.*.qty'        => 'required_with:bundles|integer|min:1',
            'bundles.*.rate'       => 'nullable|numeric|min:0',
            'bundles.*.sub_total'  => 'nullable|numeric|min:0',
            'note'                 => 'nullable|string|max:1000',
            'due_date'             => 'nullable|date',
            'due_note'             => 'nullable|string|max:2000',
            'payment_mode'         => 'nullable|in:normal,due,installment',
            'installments'         => 'nullable|array|min:1',
            'installments.*.installment_number' => 'nullable|integer|min:1',
            'installments.*.amount'             => 'required_with:installments|numeric|min:0.01',
            'installments.*.due_date'           => 'required_with:installments|date',
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

        $productIds = is_array( $request->product_id ) ? $request->product_id : [];
        $bundles    = is_array( $request->bundles ) ? $request->bundles : [];

        if ( $productIds === [] && $bundles === [] ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Add at least one product or bundle.',
            ], 400 );
        }

        $paidAmount = (float) $request->paid_amount;
        $totalPrice = (float) $request->total_price;
        $dueAmount  = max( 0, round( $totalPrice - $paidAmount, 2 ) );
        // Prefer client due_amount when provided and consistent; otherwise recalculate.
        if ( $request->filled( 'due_amount' ) ) {
            $clientDue = round( (float) $request->due_amount, 2 );
            if ( abs( $clientDue - $dueAmount ) < 0.01 ) {
                $dueAmount = $clientDue;
            }
        }

        $isFullyPaid   = $dueAmount <= 0 || $totalPrice <= $paidAmount;
        $isInstallment = $request->input( 'payment_mode' ) === 'installment'
            || ( is_array( $request->installments ) && count( $request->installments ) > 0 );

        $installmentSchedule = [];
        if ( $isInstallment ) {
            try {
                $installmentSchedule = PosInstallmentService::validateSchedule(
                    is_array( $request->installments ) ? $request->installments : [],
                    $totalPrice
                );
            } catch ( ValidationException $e ) {
                return response()->json( [
                    'status'  => 400,
                    'message' => 'Validation error',
                    'errors'  => $e->errors(),
                ], 400 );
            }
        } elseif ( ! $isFullyPaid && ! $request->filled( 'due_date' ) ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Due date is required for partial payments.',
                'errors'  => ['due_date' => ['Due date is required when payment is partial.']],
            ], 400 );
        }

        $sale                 = new PosSales();
        $sale->customer_id    = $request->customer_id;
        $sale->barcode        = $request->barcode;
        $sale->source_id      = $request->source_id;
        $sale->user_id        = Auth::id();
        $sale->sale_date      = $request->sale_date;
        $sale->payment_id     = $request->payment_id;
        $sale->paid_amount    = $paidAmount;
        $sale->total_qty      = $request->total_qty;
        $sale->total_price    = $totalPrice;
        $sale->due_amount     = $isFullyPaid ? 0 : $dueAmount;
        $sale->sale_discount  = $request->sale_discount;
        $sale->discount_type  = $request->discount_type;
        $sale->sale_date      = date( 'Y-m-d' );
        $sale->payment_status = $isFullyPaid ? 'paid' : 'due';
        $sale->vendor_id      = vendorId();
        $sale->change_amount  = $request->change_amount;
        $sale->note           = $request->note;
        $sale->save();

        // Installment plans use installments table — never mix with pos_sale_dues.
        if ( $isInstallment ) {
            PosSaleDue::where( 'pos_sales_id', $sale->id )->delete();
            PosInstallmentService::createPlan( $sale, $installmentSchedule );
            if ( $paidAmount > 0 ) {
                PosInstallmentService::applyInitialPayment( $sale, $paidAmount, (int) $request->payment_id );
            }
            $sale->refresh();
            PosInstallmentService::syncSaleFromInstallments( $sale );
        } elseif ( ! $isFullyPaid ) {
            PosSaleDue::updateOrCreate(
                ['pos_sales_id' => $sale->id],
                [
                    'due_date' => $request->due_date,
                    'due_note' => $request->due_note,
                ]
            );
        } else {
            PosSaleDue::where( 'pos_sales_id', $sale->id )->delete();
        }

        // Normal product lines
        if ( $productIds !== [] ) {
            $status = 'normal';
            ProductPosSaleService::productSaleDetails( $productIds, $sale->id, $status );
            ProductPosSaleService::productVariants( $productIds, $request->all() );
        }

        // Bundle lines: preserve bundle identity + deduct component stock
        foreach ( $bundles as $bundleRow ) {
            $bundle = ProductBundle::active()->with( 'items' )->find( (int) ( $bundleRow['bundle_id'] ?? 0 ) );
            if ( ! $bundle || $bundle->items->isEmpty() ) {
                continue;
            }

            $soldQty  = max( 1, (int) ( $bundleRow['qty'] ?? 1 ) );
            $rate     = isset( $bundleRow['rate'] )
                ? (float) $bundleRow['rate']
                : (float) $bundle->bundle_price;
            $subTotal = isset( $bundleRow['sub_total'] )
                ? (float) $bundleRow['sub_total']
                : round( $rate * $soldQty, 2 );
            $firstProductId = (int) $bundle->items->first()->product_id;

            $detail               = new \App\Models\PosSalesDetails();
            $detail->pos_sales_id = $sale->id;
            $detail->product_id   = $firstProductId;
            $detail->bundle_id    = $bundle->id;
            $detail->bundle_name  = $bundle->name;
            $detail->unit_id      = null;
            $detail->size_id      = null;
            $detail->color_id     = null;
            $detail->qty          = $soldQty;
            $detail->rate         = $rate;
            $detail->sub_total    = $subTotal;
            $detail->status       = 'bundle';
            $detail->save();

            BundleInventoryService::decrementForSale( $bundle, $soldQty, vendorId() );
        }

        if ( $request->paid_amount > 0 ) {
            $sale['partial_payment'] = 0;
            ProductPosSaleService::customerPayment( $sale );
        }

        $sale->refresh();

        return response()->json( [
            'status'         => 200,
            'message'        => 'Product successfully Sale!',
            'sale_id'        => $sale->id,
            'is_installment' => $isInstallment,
            'installments'   => $isInstallment
                ? PosInstallmentService::planSummary( $sale->fresh() )
                : null,
        ] );
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function activeBundlesForPos( $categoryId = null, $search = null )
    {
        return ProductBundle::active()
            ->with( [
                'items.product:id,name,image,sku,selling_price,discount_price',
            ] )
            ->when( $categoryId, fn ( $q ) => $q->where( 'category_id', $categoryId ) )
            ->when( $search, fn ( $q ) => $q->where( 'name', 'like', '%' . $search . '%' ) )
            ->latest()
            ->get()
            ->map( function ( ProductBundle $bundle ) {
                $regular = $bundle->calculateRegularPrice();

                return [
                    'id'            => $bundle->id,
                    'name'          => $bundle->name,
                    'type'          => 'bundle',
                    'category_id'   => $bundle->category_id,
                    'bundle_price'  => (float) $bundle->bundle_price,
                    'regular_price' => $regular,
                    'selling_price' => (float) $bundle->bundle_price,
                    'items_count'   => $bundle->items->count(),
                ];
            } );
    }

    public function show( $id ) {
        return response()->json( [
            'status' => 200,
            'logo'   => VendorInfo::where( 'vendor_id', vendorId() )->first(),
            'data'   => ProductPosSaleService::show( $id ),
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
                    ProductVariantService::incrementStock(
                        (int) $saleDetail->product_id,
                        ProductVariantService::normalizeNullableId( $saleDetail->unit_id ),
                        ProductVariantService::normalizeNullableId( $saleDetail->size_id ),
                        ProductVariantService::normalizeNullableId( $saleDetail->color_id ),
                        (int) $request->return_qty[$key],
                        vendorId()
                    );

                    // Update qty for the product — handled by ProductVariantService::incrementStock

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
        $sale = PosSales::where( 'id', $id )->where( 'vendor_id', vendorId() )->first();

        if ( $sale == null ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Invoice not found.',
            ] );
        }

        if ( $sale->isInstallmentOrder() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'This order uses installment payments. Record payment against a specific installment instead.',
            ], 400 );
        }

        if ( $sale->payment_status == 'paid' || (float) $sale->due_amount <= 0 ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'There are no outstanding payments. Thank you!',
            ] );
        }

        $validator = Validator::make( request()->all(), [
            'amount'            => 'required|numeric|min:0.01',
            'payment_method_id' => 'required|exists:payment_methods,id',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 400 );
        }

        if ( (float) $sale->due_amount < (float) request()->amount ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'The amount you entered exceeds the due amount.',
            ] );
        }

        $sale['partial_payment']        = 1;
        $sale['partial_payment_amount'] = request()->amount;
        $sale['payment_method']         = request()->payment_method_id;
        $sale['sale_date']              = date( 'Y-m-d' );
        ProductPosSaleService::customerPayment( $sale );

        $sale->refresh();
        $sale->load( 'dueRecord' );

        return response()->json( [
            'status'  => 200,
            'message' => 'Payment successfully complete !',
            'data'    => [
                'id'             => $sale->id,
                'paid_amount'    => (float) $sale->paid_amount,
                'due_amount'     => (float) $sale->due_amount,
                'payment_status' => $sale->payment_status,
                'due_status'     => $sale->due_status,
                'due_date'       => optional( $sale->dueRecord?->due_date )->format( 'Y-m-d' ),
                'due_note'       => $sale->dueRecord?->due_note,
            ],
        ] );
    }

    /**
     * Update due date / due note on an open POS due sale (pos_sale_dues table).
     */
    public function updateDue( Request $request, $id )
    {
        $sale = PosSales::where( 'id', $id )->where( 'vendor_id', vendorId() )->first();

        if ( ! $sale ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Invoice not found.',
            ], 404 );
        }

        if ( $sale->isInstallmentOrder() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'This order uses installment payments. Due management is not available for installment orders.',
            ], 400 );
        }

        if ( (float) $sale->due_amount <= 0 || $sale->payment_status === 'paid' ) {
            PosSaleDue::where( 'pos_sales_id', $sale->id )->delete();

            return response()->json( [
                'status'  => 400,
                'message' => 'This sale has no outstanding due.',
            ], 400 );
        }

        $validator = Validator::make( $request->all(), [
            'due_date' => 'nullable|date',
            'due_note' => 'nullable|string|max:2000',
            'note'     => 'nullable|string|max:1000',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 400 );
        }

        $due = PosSaleDue::firstOrNew( ['pos_sales_id' => $sale->id] );

        if ( $request->filled( 'due_date' ) ) {
            $due->due_date = $request->due_date;
        }
        if ( $request->has( 'due_note' ) ) {
            $due->due_note = $request->due_note;
        }
        $due->save();

        if ( $request->has( 'note' ) ) {
            $sale->note = $request->note;
            $sale->save();
        }

        $sale->load( 'dueRecord' );

        return response()->json( [
            'status'  => 200,
            'message' => 'Due details updated successfully.',
            'data'    => [
                'id'         => $sale->id,
                'due_date'   => optional( $sale->dueRecord?->due_date )->format( 'Y-m-d' ),
                'due_note'   => $sale->dueRecord?->due_note,
                'note'       => $sale->note,
                'due_status' => $sale->due_status,
                'due_amount' => (float) $sale->due_amount,
            ],
        ] );
    }

    public function customerDues( $customerId )
    {
        $summary = ProductPosSaleService::customerDueSummary( (int) $customerId );

        if ( ! $summary ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Customer not found.',
            ], 404 );
        }

        return response()->json( [
            'status' => 200,
            'data'   => $summary,
        ] );
    }

    public function paymentHistory() {
        return response()->json( [
            'status'          => 200,
            'payment_history' => ProductPosSaleService::paymentHistory(),
        ] );
    }

}
