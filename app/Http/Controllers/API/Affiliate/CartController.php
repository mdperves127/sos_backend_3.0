<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductAddToCartRequest;
use App\Models\Cart;
use App\Models\CartDetails;
use App\Models\Color;
use App\Models\CourierCredential;
use App\Models\DeliveryCharge;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\Unit;
use App\Models\User;
use App\Services\CrossTenantQueryService;
use App\Services\PathaoService;
use App\Services\RedxService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Tenant;
use App\Models\ProductDetails;

class CartController extends Controller {
    //
    public function addtocart( ProductAddToCartRequest $request ) {
        $validatedData = $request->validated();


        $getproduct = CrossTenantQueryService::getSingleFromTenant(
            request('tenant_id'),
            Product::class,
            function($query) {
                $query->where('id', request('product_id'))
                    ->where('status', 'active');
            }
        );

        if (!$getproduct) {
            return response()->json([
                'status' => 404,
                'message' => 'Product not found.',
            ], 404);
        }

        $totalqty   = collect( request( 'cartItems' ) )->sum( 'qty' );

        // $totalqty = collect( request( 'qty' ) )->sum();

        if ( request( 'purchase_type' ) == 'single' || ( request( 'purchase_type' ) == 'bulk' && $getproduct->is_connect_bulk_single == 1 ) ) {
            if ( $getproduct->qty < $totalqty ) {
                return responsejson( 'Quantity not available', 'fail' );
            }
        }

        if ( request( 'purchase_type' ) != 'single' ) {
            $min_bulk_qty = min( array_column( $getproduct->selling_details, 'min_bulk_qty' ) );

            if ( $min_bulk_qty > $totalqty ) {
                return responsejson( 'Minimum  Bulk Quantity ' . $min_bulk_qty . '.', 'fail' );
            }
        }

        $user_id       = 1;
        $product_id    = $getproduct->id;
        $productAmount = $getproduct->discount_price == null ? $getproduct->selling_price : $getproduct->discount_price;
        $vendor_id     = $getproduct->user_id;

        if ( request( 'purchase_type' ) == 'single' ) {
            $product_price = $getproduct->discount_price == null ? $getproduct->selling_price : $getproduct->discount_price;

            if ( $getproduct->discount_type == 'percent' ) {
                $affi_commission = ( $productAmount / 100 ) * $getproduct->discount_rate;
            } else {
                $affi_commission = $getproduct->discount_rate;
            }
            $totalproductprice          = $product_price * $totalqty;
            $total_affiliate_commission = $affi_commission * $totalqty;

            if ( $getproduct->single_advance_payment_type == 'percent' ) {
                $advancepayment = ( $product_price / 100 ) * $getproduct->advance_payment;
            } else {
                $advancepayment = $getproduct->advance_payment;
            }

            $totaladvancepayment = $getproduct->advance_payment * $totalqty;
        } else {
            $bulkdetails       = collect( $getproduct->selling_details )->where( 'min_bulk_qty', '<=', $totalqty )->max();
            $product_price     = $bulkdetails['min_bulk_price'];
            $totalproductprice = $product_price * $totalqty;

            if ( $bulkdetails['bulk_commission_type'] == 'percent' ) {
                $bulk_commission = ( $product_price / 100 ) * $bulkdetails['bulk_commission'];
            } else {
                $bulk_commission = $bulkdetails['bulk_commission'];
            }

            $total_affiliate_commission = $bulk_commission * $totalqty;
            $affi_commission            = $bulk_commission;

            if ( $bulkdetails['advance_payment_type'] == 'percent' ) {
                $advancepayment = ( $product_price / 100 ) * $bulkdetails['advance_payment'];
            } else {
                $advancepayment = $bulkdetails['advance_payment'];
            }

            $totaladvancepayment = $advancepayment * $totalqty;
        }

        if ( Cart::where( 'product_id', $product_id )->where( 'user_id', $user_id )->exists() ) {
            return response()->json( [
                'status'  => 409,
                'message' => $getproduct->name . ' already added to cart.',
            ] );
        }

        $obj = collect( request()->all() );

        DB::beginTransaction();

        // try {
            // Save cart in tenant DB and get created instance
            $cartitem = CrossTenantQueryService::saveToTenant( tenant()->id, Cart::class, function ( $cart ) use (
                $user_id,
                $product_id,
                $product_price,
                $vendor_id,
                $affi_commission,
                $getproduct,
                $totalqty,
                $totalproductprice,
                $total_affiliate_commission,
                $advancepayment,
                $totaladvancepayment
            ) {
                $cart->user_id                    = $user_id;
                $cart->product_id                 = $product_id;
                $cart->product_price              = $product_price;
                $cart->vendor_id                  = $vendor_id;
                $cart->amount                     = $affi_commission;
                $cart->category_id                = $getproduct->category_id;
                $cart->product_qty                = $totalqty;
                $cart->totalproductprice          = $totalproductprice;
                $cart->total_affiliate_commission = $total_affiliate_commission;
                $cart->purchase_type              = request( 'purchase_type' );
                $cart->advancepayment             = $advancepayment;
                $cart->totaladvancepayment        = $totaladvancepayment;
                $cart->tenant_id                  = request('tenant_id');
            } );

            if ( !$cartitem ) {
                throw new \RuntimeException('Failed to create cart in tenant database');
            }

            foreach ( request( 'cartItems' ) as $data ) {
                $colors[]   = $data['color'] ?? null;
                $sizes[]    = $data['size'] ?? null;
                $qnts[]     = $data['qty'] ?? 1;
                $variants[] = $data['variant_id'] ?? null;
                $units[]    = $data['unit'] ?? null;
            }

            foreach ( $qnts as $key => $value ) {
                CrossTenantQueryService::saveToTenant( tenant()->id, CartDetails::class, function ( $cartDetail ) use (
                    $cartitem,
                    $colors,
                    $sizes,
                    $variants,
                    $units,
                    $key,
                    $value
                ) {
                    $cartDetail->cart_id    = $cartitem->id;
                    $cartDetail->color      = $colors[$key];
                    $cartDetail->size       = $sizes[$key];
                    $cartDetail->qty        = $value;
                    $cartDetail->variant_id = $variants[$key];
                    $cartDetail->unit_id    = $units[$key];
                } );
            }

            DB::commit();

        // } catch ( \Exception $e ) {
        //     DB::rollBack();
        //     return response()->json( ['error' => 'Failed to create cart.'], 500 );
        // }

        return response()->json( [
            'status'  => 201,
            'message' => 'Added to Cart',
        ] );

    }

    public function viewcart() {
        // Step 1: Get all carts from current tenant's OWN database (cart table in current tenant DB)
        $currentTenant = tenant();
        if ( !$currentTenant ) {
            return response()->json( [
                'status' => 400,
                'message' => 'No tenant context found',
                'products' => [],
            ] );
        }

        // Query cart table directly from current tenant's database
        $cartitems = Cart::whereNotNull( 'tenant_id' )
            ->with( ['cartDetails'] )
            ->get();

        if ( $cartitems->isEmpty() ) {
            return response()->json( [
                'status' => 200,
                'products' => [],
            ] );
        }

        // Step 2: Get unique tenant IDs from carts (these are the tenants whose products are in cart)
        $tenantIds = $cartitems->pluck( 'tenant_id' )->unique()->filter()->values();

        if ( $tenantIds->isEmpty() ) {
            return response()->json( [
                'status' => 200,
                'products' => [],
            ] );
        }

        // Step 3: Group carts by tenant_id and product_id to get unique product IDs per tenant
        $productIdsByTenant = [];
        foreach ( $cartitems as $cartItem ) {
            $tenantId = $cartItem->tenant_id;
            $productId = $cartItem->product_id;

            if ( $tenantId && $productId ) {
                if ( !isset( $productIdsByTenant[$tenantId] ) ) {
                    $productIdsByTenant[$tenantId] = [];
                }
                if ( !in_array( $productId, $productIdsByTenant[$tenantId] ) ) {
                    $productIdsByTenant[$tenantId][] = $productId;
                }
            }
        }

        // Step 4: Load cartDetails relationships from product tenant's database
        foreach ( $cartitems as $cartItem ) {
            if ( $cartItem->cartDetails && $cartItem->cartDetails->count() > 0 ) {
                // Get the product tenant
                $productTenant = Tenant::on('mysql')->where('id', $cartItem->tenant_id)->first();

                if ( $productTenant ) {
                    // Configure tenant connection for this product tenant
                    $connectionName = 'tenant_' . $productTenant->id;
                    $databaseName = 'sosanik_tenant_' . $productTenant->id;
                    $originalTenantConfig = config('database.connections.tenant');

                    config([
                        'database.connections.' . $connectionName => [
                            'driver' => 'mysql',
                            'host' => config('database.connections.mysql.host'),
                            'port' => config('database.connections.mysql.port'),
                            'database' => $databaseName,
                            'username' => config('database.connections.mysql.username'),
                            'password' => config('database.connections.mysql.password'),
                            'charset' => 'utf8mb4',
                            'collation' => 'utf8mb4_unicode_ci',
                            'strict' => false,
                        ],
                        'database.connections.tenant' => [
                            'driver' => 'mysql',
                            'host' => config('database.connections.mysql.host'),
                            'port' => config('database.connections.mysql.port'),
                            'database' => $databaseName,
                            'username' => config('database.connections.mysql.username'),
                            'password' => config('database.connections.mysql.password'),
                            'charset' => 'utf8mb4',
                            'collation' => 'utf8mb4_unicode_ci',
                            'strict' => false,
                        ]
                    ]);
                    DB::purge($connectionName);
                    DB::purge('tenant');

                    // Load relationships for each cartDetail
                    foreach ( $cartItem->cartDetails as $cartDetail ) {
                        // Load color from product tenant database
                        if ( $cartDetail->color ) {
                            $colorModel = Color::on('tenant')->where('id', $cartDetail->color)->first();
                            $cartDetail->setRelation('color', $colorModel);
                        }

                        // Load size from product tenant database
                        if ( $cartDetail->size ) {
                            $sizeModel = Size::on('tenant')->where('id', $cartDetail->size)->first();
                            $cartDetail->setRelation('size', $sizeModel);
                        }

                        // Load unit from product tenant database
                        if ( $cartDetail->unit_id ) {
                            $unitModel = Unit::on('tenant')->where('id', $cartDetail->unit_id)->first();
                            $cartDetail->setRelation('unit', $unitModel);
                        }

                        // Load variant from product tenant database with relationships
                        if ( $cartDetail->variant_id ) {
                            $variantModel = ProductVariant::on('tenant')
                                ->where('id', $cartDetail->variant_id)
                                ->with(['color', 'size', 'unit'])
                                ->first();
                            $cartDetail->setRelation('variant', $variantModel);
                        }
                    }

                    // Restore original tenant connection config
                    if ( $originalTenantConfig !== null ) {
                        config(['database.connections.tenant' => $originalTenantConfig]);
                    } else {
                        config(['database.connections.tenant' => null]);
                    }
                    DB::purge('tenant');
                }
            }
        }

        // Step 5: Create a mapping of cart items by tenant_id and product_id for quick lookup
        $cartMap = [];
        foreach ( $cartitems as $cartItem ) {
            $key = $cartItem->tenant_id . '_' . $cartItem->product_id;
            if ( !isset( $cartMap[$key] ) ) {
                $cartMap[$key] = [];
            }

            // Store full cart item data including quantity and cartDetails
            $cartMap[$key][] = [
                'id' => $cartItem->id,
                'product_qty' => $cartItem->product_qty,
                'cartDetails' => $cartItem->cartDetails,
                'product_price' => $cartItem->product_price,
                'totalproductprice' => $cartItem->totalproductprice,
                'total_affiliate_commission' => $cartItem->total_affiliate_commission,
                'purchase_type' => $cartItem->purchase_type,
                'advancepayment' => $cartItem->advancepayment,
                'totaladvancepayment' => $cartItem->totaladvancepayment,
            ];
        }

        // Step 5: Get all products from all tenants and combine with cart data
        $allProducts = collect();

        foreach ( $productIdsByTenant as $tenantId => $productIds ) {
            // Query Tenant from mysql connection (central database)
            $tenant = Tenant::on('mysql')->where('id', $tenantId)->first();

            if ( !$tenant ) {
                continue;
            }

            // First, try without filters to see if products exist at all
            $allProductsForTenant = CrossTenantQueryService::queryTenant(
                $tenant,
                Product::class,
                function ( $query ) use ( $productIds ) {
                    $query->whereIn( 'id', $productIds );
                }
            );

            // Get products from this tenant's database using product_id with filters
            $products = CrossTenantQueryService::queryTenant(
                $tenant,
                Product::class,
                function ( $query ) use ( $productIds ) {
                    $query->whereIn( 'id', $productIds )
                        ->where( 'status', 'active' )
                        ->whereHas( 'productdetails', function ( $query ) {
                            $query->where( 'status', 1 );
                        } );
                }
            );

            // If no products found with filters, use all products (or remove problematic filters)
            if ( $products->isEmpty() && $allProductsForTenant->isNotEmpty() ) {
                $products = $allProductsForTenant;
            }

            // Step 6: Add each product to the unified array with tenant context and cart data
            foreach ( $products as $product ) {
                // Ensure tenant context is attached to each product
                $product->tenant_id = $tenant->id;
                $product->tenant_name = $tenant->company_name;

                // Find matching cart item(s) for this product
                $key = $tenant->id . '_' . $product->id;
                if ( isset( $cartMap[$key] ) && !empty( $cartMap[$key] ) ) {
                    // If there's a cart item, add cart_id and cart data with quantity
                    $cartItem = $cartMap[$key][0]; // Get first matching cart item
                    $product->cart_id = $cartItem['id'];
                    $product->cart_qty = $cartItem['product_qty'] ?? 0; // Add quantity from cart
                    $product->cart_data = $cartItem;

                    // If cartDetails exist, also attach them
                    if ( isset( $cartItem['cartDetails'] ) && $cartItem['cartDetails'] ) {
                        $product->cart_details = $cartItem['cartDetails'];
                    }
                } else {
                    // If no cart item found, set cart_id to null
                    $product->cart_id = null;
                    $product->cart_qty = 0;
                    $product->cart_data = null;
                    $product->cart_details = null;
                }

                // Add to unified products array
                $allProducts->push( $product );
            }
        }

        return response()->json( [
            'status'   => 200,
            'products' => $allProducts->values()->all(),
        ] );
    }

    public function deleteCartitem( $cart_id ) {

        $cartitem = Cart::where( 'id', $cart_id )->first();
        if ( $cartitem ) {
            $cartitem->delete();
            return response()->json( [
                'status'  => 200,
                'message' => 'Cart Item Removed Successfully.',
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'Cart Item not Found',
            ] );
        }
    }

    function affiliatorCart( $tenant_id, $id ) {
        try {
            // Step 1: Get cart from current tenant's database
            $cart = Cart::with( ['cartDetails'] )->where( 'tenant_id', $tenant_id )->find( $id );

            if ( !$cart ) {
                return response()->json( [
                    'status' => 'Not found',
                    'message' => 'Cart not found',
                ] );
            }

            // Step 2: Get the tenant whose product is in this cart
            if ( !$cart->tenant_id ) {
                return response()->json( [
                    'status' => 'error',
                    'message' => 'Cart does not have tenant_id',
                ] );
            }

            $productTenant = Tenant::on('mysql')->where('id', $tenant_id)->first();

            if ( !$productTenant ) {
                return response()->json( [
                    'status' => 'error',
                    'message' => 'Product tenant not found',
                ] );
            }

            // Step 3: Configure tenant connection for eager loading relationships
            $connectionName = 'tenant_' . $productTenant->id;
            $databaseName = 'sosanik_tenant_' . $productTenant->id;

            // Store original tenant connection config if it exists
            $originalTenantConfig = config('database.connections.tenant');

            // Configure both the dynamic connection name and the 'tenant' connection
            // The 'tenant' connection is needed because related models use protected $connection = 'tenant'
            config([
                'database.connections.' . $connectionName => [
                    'driver' => 'mysql',
                    'host' => config('database.connections.mysql.host'),
                    'port' => config('database.connections.mysql.port'),
                    'database' => $databaseName,
                    'username' => config('database.connections.mysql.username'),
                    'password' => config('database.connections.mysql.password'),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'strict' => false,
                ],
                'database.connections.tenant' => [
                    'driver' => 'mysql',
                    'host' => config('database.connections.mysql.host'),
                    'port' => config('database.connections.mysql.port'),
                    'database' => $databaseName,
                    'username' => config('database.connections.mysql.username'),
                    'password' => config('database.connections.mysql.password'),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'strict' => false,
                ]
            ]);
            DB::purge($connectionName);
            DB::purge('tenant');

            // Get product from product's tenant database with relationships
            // Pass tenant ID instead of tenant object, and CrossTenantQueryService will handle connection internally
            $product = CrossTenantQueryService::getSingleFromTenant(
                $productTenant->id,
                Product::class,
                function ( $query ) use ( $cart ) {
                    $query->where( 'id', $cart->product_id )
                        ->where( 'status', 'active' )
                        ->with([
                            'colors',
                            'sizes',
                            'productVariant.unit',
                            'productVariant.color',
                            'productVariant.size'
                        ]);
                }
            );

            // Re-configure tenant connection for cartDetails relationship loading
            // (CrossTenantQueryService may have changed the connection)
            config([
                'database.connections.tenant' => [
                    'driver' => 'mysql',
                    'host' => config('database.connections.mysql.host'),
                    'port' => config('database.connections.mysql.port'),
                    'database' => $databaseName,
                    'username' => config('database.connections.mysql.username'),
                    'password' => config('database.connections.mysql.password'),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'strict' => false,
                ]
            ]);
            DB::purge('tenant');

            // Load cartDetails relationships (color, size, unit, variant) from product tenant's database
            // These need to be loaded while the tenant connection is still configured
            if ($cart->cartDetails && $cart->cartDetails->count() > 0) {
                foreach ($cart->cartDetails as $cartDetail) {
                    // Load color from product tenant database
                    if ($cartDetail->color) {
                        $colorModel = Color::on('tenant')->where('id', $cartDetail->color)->first();
                        $cartDetail->setRelation('color', $colorModel);
                    }

                    // Load size from product tenant database
                    if ($cartDetail->size) {
                        $sizeModel = Size::on('tenant')->where('id', $cartDetail->size)->first();
                        $cartDetail->setRelation('size', $sizeModel);
                    }

                    // Load unit from product tenant database
                    if ($cartDetail->unit_id) {
                        $unitModel = Unit::on('tenant')->where('id', $cartDetail->unit_id)->first();
                        $cartDetail->setRelation('unit', $unitModel);
                    }

                    // Load variant from product tenant database with relationships
                    if ($cartDetail->variant_id) {
                        $variantModel = ProductVariant::on('tenant')
                            ->where('id', $cartDetail->variant_id)
                            ->with(['color', 'size', 'unit'])
                            ->first();
                        $cartDetail->setRelation('variant', $variantModel);
                    }
                }
            }

            // Restore original tenant connection config
            if ($originalTenantConfig !== null) {
                config(['database.connections.tenant' => $originalTenantConfig]);
            } else {
                config(['database.connections.tenant' => null]);
            }
            DB::purge('tenant');

            if ( !$product ) {
                return response()->json( [
                    'status' => 'error',
                    'message' => 'Product not found or inactive',
                ] );
            }

            // Attach product to cart
            $cart->product = $product;

            // Step 4: Get delivery charge and courier credentials from product's tenant database
            $deliverCredential = CrossTenantQueryService::queryTenant(
                $productTenant,
                DeliveryCharge::class,
                function ( $query ) use ( $cart ) {
                    $query->where( 'vendor_id', $cart->vendor_id )
                        ->select( 'id', 'area', 'charge' );
                }
            );

            // Step 5: Get courier credentials from product's tenant database
            $courier = CrossTenantQueryService::queryTenant(
                $productTenant,
                CourierCredential::class,
                function ( $query ) use ( $cart ) {
                    $query->where( 'vendor_id', $cart->vendor_id )
                        ->where( 'status', 'active' )
                        ->select( 'id', 'courier_name', 'status', 'default' )
                        ->with('colors', 'sizes', 'units');
                }
            );

            $default = CrossTenantQueryService::getSingleFromTenant(
                $cart->tenant_id,
                CourierCredential::class,
                function ( $query ) use ( $cart ) {
                    $query->where( 'vendor_id', $cart->vendor_id )
                        ->where( 'default', 'yes' );
                }
            );

            $cities = [];
            $areas = [];

            if ( $default && $default->courier_name == 'pathao' ) {
                $access_token = PathaoService::getToken( $default->api_key, $default->secret_key, $default->client_email, $default->client_password );

                if ( $access_token ) {
                    $cities = PathaoService::cities( $access_token ) ?? [];
                }

                $default = $default->only( 'id', 'courier_name', 'status', 'default' );
            } elseif ( $default && $default->courier_name == 'redx' ) {
                $apiKey = courierCredential( $cart->vendor_id, 'redx' );

                if ( $apiKey ) {
                    $areas = RedxService::getArea( $apiKey->api_key );
                    $areas = json_decode( $areas, true ) ?? [];
                }
            }

            // Get profit_amount from ProductDetails in the product tenant's database
            $productDetails = CrossTenantQueryService::getSingleFromTenant(
                tenant()->id,
                ProductDetails::class,
                function ( $query ) use ( $cart ) {
                    $query->where( 'product_id', $cart->product_id );
                }
            );
            $profit_amount = $productDetails ? $productDetails->profit_amount : null;


            return response()->json( [
                "data"            => $cart,
                "deliveryArea"    => $deliverCredential->values()->all(),
                "courier"         => $courier->values()->all(),
                "cities"          => $cities,
                'default_courier' => $default,
                'areas'           => $areas,
                'profit_amount'   => $profit_amount,
            ] );

        } catch ( \Exception $e ) {
            return response()->json( [
                'status'  => 403,
                'message' => 'Your courier credential is invalid, please check and try again.',
                'error' => $e->getMessage(),
            ] );
        }
    }
}
