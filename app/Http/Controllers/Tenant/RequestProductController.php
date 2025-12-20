<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\VendorProductrequestRequest;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CrossTenantQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RequestProductController extends Controller {

    function RequestPending() {
        // Check if the user is an employee and has permission
        // if ( Auth::user()->is_employee === 'yes' && employee( 'pending_request' ) === null ) {
        //     return $this->employeeMessage();
        // }

        $search  = request( 'search' );
        $product = ProductDetails::query()
            ->with( ['product' => function ( $query ) {
                $query->select( 'id', 'name', 'selling_price', 'image' )
                    ->with( 'productImage' );
            }] )
            ->where( 'vendor_id', auth()->user()->id )

            ->where( 'status', '2' )
            ->whereHas( 'product' )
            ->when( $search != '', function ( $query ) use ( $search ) {
                $query->whereHas( 'product', function ( $query ) use ( $search ) {
                    $query->where( 'name', 'like', '%' . $search . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $search . '%' );
            } )
            ->with( ['affiliator:id,name', 'vendor:id,name'] )
            ->whereHas( 'affiliator', function ( $query ) {
                $query->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->whereHas( 'usersubscription', function ( $query ) {
                        $query->where( 'expire_date', '>', now() );
                    } )
                    ->withSum( 'usersubscription', 'product_approve' )
                    ->having( 'affiliatoractiveproducts_count', '<=', DB::raw( 'usersubscription_sum_product_approve' ) );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );
    }

    function membershipexpireactiveproduct() {
        $search  = request( 'search' );
        $product = ProductDetails::query()
            ->withWhereHas( 'product', function ( $query ) {
                $query->select( 'id', 'name', 'selling_price', 'image' )
                    ->with( 'productImage' );
            } )
            ->where( ['vendor_id' => auth()->id(), 'status' => 1] )
            ->when( $search != '', function ( $query ) use ( $search ) {
                $query->whereHas( 'product', function ( $query ) use ( $search ) {
                    $query->where( 'name', 'like', '%' . $search . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $search . '%' );
            } )
            ->with( ['affiliator:id,name', 'vendor:id,name'] )
            ->whereHas( 'affiliator', function ( $query ) {
                $query->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->whereHas( 'usersubscription', function ( $query ) {
                        $query->where( 'expire_date', '<=', now() );
                    } );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );
    }

    function RequestView( $id ) {
        $product = ProductDetails::query()
            ->with( ['product' => function ( $query ) {
                $query->with( 'productImage' );
            }] )
            ->where( 'vendor_id', auth()->id() )
            ->whereHas( 'affiliator', function ( $query ) {
                $query->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->whereHas( 'usersubscription', function ( $query ) {
                        $query->where( 'expire_date', '>', now() );
                    } )
                    ->withSum( 'usersubscription', 'product_approve' )
                    ->having( 'affiliatoractiveproducts_count', '<=', DB::raw( 'usersubscription_sum_product_approve' ) );
            } )
            ->find( $id );
        if ( !$product ) {
            return $this->response( 'Not found' );
        }

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );
    }

    function RequestActive() {

        // Check if the user is an employee and has permission
        if ( Auth::user()->is_employee === 'yes' && employee( 'active_request' ) === null ) {
            return $this->employeeMessage();
        }

        $search  = request( 'search' );
        $product = ProductDetails::query()
            ->with( ['product' => function ( $query ) {
                $query->select( 'id', 'name', 'selling_price', 'image' )
                    ->with( 'productImage' );
            }] )
            ->where( 'vendor_id', auth()->user()->id )
            ->where( 'status', 1 )
            ->whereHas( 'product' )
            ->when( $search != '', function ( $query ) use ( $search ) {
                $query->whereHas( 'product', function ( $query ) use ( $search ) {
                    $query->where( 'name', 'like', '%' . $search . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $search . '%' );
            } )
            ->with( ['affiliator:id,name', 'vendor:id,name'] )
            ->withWhereHas( 'affiliator', function ( $query ) {
                $query->select( 'id', 'name' )->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->withWhereHas( 'usersubscription', function ( $query ) {
                        $query->select( 'user_id', 'chat_access' )->where( 'expire_date', '>', now() );
                    } );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );
    }

    public function RequestAll()
    {
        $search  = request( 'search' );
        $orderId = request( 'order_id' );

        // Get all dropshipper tenants
        $tenants = Tenant::on( 'mysql' )->where( 'type', 'dropshipper' )->get();

        // Step 1: Query ProductDetails from ALL dropshipper tenant databases
        $allProductDetails = collect();

        foreach ( $tenants as $tenant ) {
            try {
                $connectionName = 'tenant_' . $tenant->id;
                $databaseName   = 'sosanik_tenant_' . $tenant->id;

                // Configure tenant connection
                config( [
                    'database.connections.' . $connectionName => [
                        'driver'   => 'mysql',
                        'host'     => config( 'database.connections.mysql.host' ),
                        'port'     => config( 'database.connections.mysql.port' ),
                        'database' => $databaseName,
                        'username' => config( 'database.connections.mysql.username' ),
                        'password' => config( 'database.connections.mysql.password' ),
                        'charset'  => 'utf8mb4',
                        'collation'=> 'utf8mb4_unicode_ci',
                        'strict'   => false,
                    ],
                ] );
                DB::purge( $connectionName );

                // Build query for this tenant's database
                $query = DB::connection( $connectionName )->table( 'product_details' )
                    ->where( 'product_details.tenant_id', tenant()->id );

                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'product_details.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'products.name', 'like', "%{$search}%" )
                                ->orWhere( 'product_details.uniqid', 'like', "%{$search}%" );
                          } )
                          ->select(
                              'product_details.id',
                              'product_details.product_id',
                              'product_details.status',
                              'product_details.reason',
                              'product_details.uniqid',
                              'product_details.created_at',
                              'product_details.updated_at',
                              DB::raw( 'product_details.tenant_id as stored_tenant_id' )
                          )
                          ->groupBy( 'product_details.id' );
                } else {
                    // If no search, select all columns but alias tenant_id to preserve it
                    $query->select(
                        'product_details.id',
                        'product_details.product_id',
                        'product_details.status',
                        'product_details.reason',
                        'product_details.uniqid',
                        'product_details.created_at',
                        'product_details.updated_at',
                        DB::raw( 'product_details.tenant_id as stored_tenant_id' )
                    );
                }

                // Filter by order_id (ProductDetails id)
                if ( $orderId ) {
                    $query->where( 'product_details.id', 'like', "%{$orderId}%" );
                }

                // Order by latest
                $query->orderBy( 'product_details.created_at', 'desc' );

                // Execute query for this tenant
                $tenantResults = $query->get();

                // Add tenant context to each result
                $tenantResults->transform( function ( $item ) use ( $tenant ) {
                    $domain = $tenant->domains()->first();
                    $item->tenant_id = $tenant->id;
                    $item->tenant_name = $tenant->company_name;
                    $item->tenant_owner = $tenant->owner_name;
                    return $item;
                } );

                $allProductDetails = $allProductDetails->merge( $tenantResults );
            } catch ( \Exception $e ) {
                \Log::warning( "Failed to query dropshipper tenant {$tenant->id}: " . $e->getMessage() );
                continue;
            } finally {
                // Reconnect to central database
                DB::setDefaultConnection( 'mysql' );
            }
        }

        // Step 2: For each ProductDetails record, use its stored tenant_id to load the Product from that tenant's database
        $productDetails = collect( $allProductDetails )->map( function ( $productDetail ) {
            // Use stored_tenant_id (the tenant_id COLUMN from product_details table)
            // This tells us which tenant database contains the product
            // Example: if stored_tenant_id = "two" and product_id = 1, get product id=1 from tenant "two"'s database
            $storedTenantId = $productDetail->stored_tenant_id ?? null;

            if ( !$storedTenantId || !isset( $productDetail->product_id ) ) {
                return $productDetail;
            }

            // Lookup tenant from central database
            $tenant = Tenant::on( 'mysql' )->find( $storedTenantId );
            if ( !$tenant ) {
                return $productDetail;
            }

            $connectionName = 'tenant_' . $tenant->id;
            $databaseName   = 'sosanik_tenant_' . $tenant->id;

            // Configure connection to the tenant database specified by tenant_id column
            config( [
                'database.connections.' . $connectionName => [
                    'driver'   => 'mysql',
                    'host'     => config( 'database.connections.mysql.host' ),
                    'port'     => config( 'database.connections.mysql.port' ),
                    'database' => $databaseName,
                    'username' => config( 'database.connections.mysql.username' ),
                    'password' => config( 'database.connections.mysql.password' ),
                    'charset'  => 'utf8mb4',
                    'collation'=> 'utf8mb4_unicode_ci',
                    'strict'   => false,
                ],
            ] );
            DB::purge( $connectionName );

            // Load product from the tenant database specified by tenant_id
            // Example: if tenant_id = "two" and product_id = 1, get product id=1 from tenant "two"'s database
            $product = Product::on( $connectionName )
                ->select( 'id', 'name', 'selling_price', 'image' )
                ->find( $productDetail->product_id );

            if ( $product ) {
                $product->load( 'productImage' );
            }
            $productDetail->product = $product;

            // Load vendor from the same tenant database
            if ( isset( $productDetail->vendor_id ) ) {
                $vendor = User::on( $connectionName )
                    ->select( 'id', 'name' )
                    ->find( $productDetail->vendor_id );
                $productDetail->vendor = $vendor;
            }

            // Load affiliator from the same tenant database
            if ( isset( $productDetail->user_id ) ) {
                $affiliator = User::on( $connectionName )
                    ->select( 'id', 'name' )
                    ->find( $productDetail->user_id );
                $productDetail->affiliator = $affiliator;
            }

            return $productDetail;
        } );

        // Ensure consistent latest-first ordering
        $productDetails = $productDetails->sortByDesc( function ( $productDetail ) {
            return $productDetail->created_at ?? '';
        } )->values();

        // Manual pagination after processing
        $page    = (int) request()->get( 'page', 1 );
        $perPage = 10;
        $offset  = ( $page - 1 ) * $perPage;

        $paginatedProductDetails = $productDetails->slice( $offset, $perPage );
        $total                   = $productDetails->count();
        $lastPage                = (int) max( 1, ceil( $total / $perPage ) );

        // Build pagination URLs
        $path        = request()->url();
        $queryParams = request()->query();
        $buildUrl    = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        $response = [
            'data'            => $paginatedProductDetails->values(),
            'current_page'    => $page,
            'per_page'        => $perPage,
            'total'           => $total,
            'last_page'       => $lastPage,
            'from'            => $total ? $offset + 1 : null,
            'to'              => min( $offset + $perPage, $total ),
            'path'            => $path,
            'first_page_url'  => $buildUrl( 1 ),
            'last_page_url'  => $total ? $buildUrl( $lastPage ) : null,
            'prev_page_url'  => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url'  => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'product' => $response,
        ] );
    }

    function RequestRejected() {

        // Check if the user is an employee and has permission
        if ( Auth::user()->is_employee === 'yes' && employee( 'reject_request' ) === null ) {
            return $this->employeeMessage();
        }

        $search  = request( 'search' );
        $product = ProductDetails::query()
            ->where( ['vendor_id' => auth()->id(), 'status' => 3] )
            ->withWhereHas( 'product', function ( $query ) {
                $query->select( 'id', 'name', 'selling_price', 'image' )
                    ->with( 'productImage' );
            } )
            ->when( $search != '', function ( $query ) use ( $search ) {
                $query->whereHas( 'product', function ( $query ) use ( $search ) {
                    $query->where( 'name', 'like', '%' . $search . '%' );
                } )
                    ->orWhere( 'uniqid', 'like', '%' . $search . '%' );
            } )
            ->with( ['affiliator:id,name', 'vendor:id,name'] )
            ->whereHas( 'affiliator', function ( $query ) {
                $query->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->whereHas( 'usersubscription', function ( $query ) {
                        $query->where( 'expire_date', '>', now() );
                    } )
                    ->withSum( 'usersubscription', 'product_approve' )
                    ->having( 'affiliatoractiveproducts_count', '<=', DB::raw( 'usersubscription_sum_product_approve' ) );
            } )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();

        return response()->json( [
            'status'  => 200,
            'product' => $product,
        ] );
    }

    function RequestUpdate( VendorProductrequestRequest $request, $tenant_id, $id ) {
        $validatedData = $request->validated();


        $tenant = Tenant::on( 'mysql' )->find( $tenant_id );

        $data = CrossTenantQueryService::getSingleFromTenant(
            $tenant_id,
            ProductDetails::class,
            function ( $query ) use ( $id ) {
                $query->where( 'id', $id );
            }
        );

        if ( $data ) {

            // if ( request( 'status' ) == 1 ) {
            //     $getmembershipdetails = getmembershipdetails();

            //     $affiliaterequest = $getmembershipdetails?->affiliate_request;

            //     $totalrequest = ProductDetails::where( ['vendor_id' => vendorId(), 'status' => 1] )->count();

            //     if ( ismembershipexists( vendorId() ) != 1 ) {
            //         return responsejson( 'You do not have a membership', 'fail' );
            //     }

            //     if ( isactivemembership( vendorId() ) != 1 ) {
            //         return responsejson( 'Membership expired!', 'fail' );
            //     }

            //     if ( $affiliaterequest <= $totalrequest ) {
            //         return responsejson( 'You can not accept product request more than ' . $affiliaterequest . '.', 'fail' );
            //     }
            // }

            // Get the original tenant_id from the database before it was overwritten by getSingleFromTenant
            // We need to query it directly from the database to get the actual stored value
            $connectionName = 'tenant_' . $tenant_id;
            $databaseName   = 'sosanik_tenant_' . $tenant_id;

            config( [
                'database.connections.' . $connectionName => [
                    'driver'   => 'mysql',
                    'host'     => config( 'database.connections.mysql.host' ),
                    'port'     => config( 'database.connections.mysql.port' ),
                    'database' => $databaseName,
                    'username' => config( 'database.connections.mysql.username' ),
                    'password' => config( 'database.connections.mysql.password' ),
                    'charset'  => 'utf8mb4',
                    'collation'=> 'utf8mb4_unicode_ci',
                    'strict'   => false,
                ],
            ] );
            DB::purge( $connectionName );

            $originalTenantId = DB::connection( $connectionName )
                ->table( 'product_details' )
                ->where( 'id', $id )
                ->value( 'tenant_id' );

            // Remove tenant context attributes that were added by getSingleFromTenant
            // These are not actual database columns and will cause errors if saved
            unset($data->tenant_domain);
            unset($data->tenant_name);

            // Restore the original tenant_id value (the one stored in the database)
            // The getSingleFromTenant overwrites it with the queried tenant's ID, but we need to keep the original
            if ( $originalTenantId !== null ) {
                $data->tenant_id = $originalTenantId;
            }

            $data->status = request( 'status' );
            $data->reason = request( 'reason' );
            $data->save();

            // $existingConversation = Conversation::on( $connectionName )->where( 'sender_id', vendorId() )
            //     ->where( 'receiver_id', $request->user_id )
            //     ->orWhere( 'sender_id', $request->user_id )
            //     ->where( 'receiver_id', vendorId() )
            //     ->first();

            // if ( request( 'status' ) == 1 AND !$existingConversation ) {
            //     Conversation::create( [
            //         'sender_id'   => vendorId(),
            //         'receiver_id' => $data->user_id,
            //     ] );
            // }

            //For Notification
            // $user = User::where('id',$data->user_id)->first();
            // $product = Product::where('id',$data->product_id)->first();
            // $text = "Your requested product " .$product->name . request('status') == 2 ? "was accepted !" : "was rejected !";
            // Notification::send($user, new AffiliateProductRequestStatusNotification($user, $text, $product));

        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'Not found',
            ] );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'updated successfully',
        ] );
    }

    public function affiliateRequestCount() {

        $count = ProductDetails::where( 'vendor_id', auth()->user()->id )
            ->when( request( 'status' ) == 'pending', function ( $q ) {
                return $q->where( 'status', '2' );
            } )
            ->when( request( 'status' ) == 'rejected', function ( $q ) {
                return $q->where( 'status', '3' );
            } )
            ->when( request( 'status' ) == 'active', function ( $q ) {
                return $q->where( 'status', '1' );
            } )->count();
        return response()->json( [
            'status' => 200,
            'count'  => $count,
        ] );
    }

    function membershipexpireactiveproductCount() {
        $expire_request_count = ProductDetails::query()
            ->where( ['vendor_id' => auth()->id(), 'status' => 1] )
            ->whereHas( 'affiliator', function ( $query ) {
                $query->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->whereHas( 'usersubscription', function ( $query ) {
                        $query->where( 'expire_date', '<=', now() );
                    } );
            } )
            ->count();

        return response()->json( [
            'status'               => 200,
            'expire_request_count' => $expire_request_count,
        ] );
    }
}
