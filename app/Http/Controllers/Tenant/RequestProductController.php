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
        if ( Auth::user()->role_type === 'employee' && !tenantPermission( 'pending_request' ) ) {
            return $this->employeeMessage();
        }

        return response()->json( [
            'status'  => 200,
            'product' => $this->dropshipperProductRequests( '2' ),
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
                    ->whereCentralSubscription( false );
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
                $query->whereCentralSubscription()
                    ->withinCentralSubscriptionProductApproveLimit();
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
        if ( Auth::user()->role_type === 'employee' && !tenantPermission( 'active_request' ) ) {
            return $this->employeeMessage();
        }

        return response()->json( [
            'status'  => 200,
            'product' => $this->dropshipperProductRequests( '1' ),
        ] );
    }

    public function RequestAll()
    {
        return response()->json( [
            'status'  => 200,
            'product' => $this->dropshipperProductRequests(),
        ] );
    }

    /**
     * Dropshipper product requests for the current merchant tenant live in dropshipper tenant DBs.
     */
    private function dropshipperProductRequests( ?string $status = null, ?string $search = null, ?string $orderId = null ): array {
        $search  = $search ?? request( 'search' );
        $orderId = $orderId ?? request( 'order_id' );

        $tenants = Tenant::on( 'mysql' )->where( 'type', 'dropshipper' )->get();

        $allProductDetails = collect();

        foreach ( $tenants as $tenant ) {
            try {
                $connectionName = 'tenant_' . $tenant->id;
                $databaseName   = 'affsellc_' . $tenant->id;

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

                $query = DB::connection( $connectionName )->table( 'product_details' )
                    ->where( 'product_details.tenant_id', tenant()->id );

                if ( $status !== null ) {
                    $query->where( 'product_details.status', $status );
                }

                if ( $search ) {
                    $query->leftJoin( 'products', 'product_details.product_id', '=', 'products.id' )
                        ->where( function ( $q ) use ( $search ) {
                            $q->where( 'products.name', 'like', "%{$search}%" )
                                ->orWhere( 'product_details.uniqid', 'like', "%{$search}%" );
                        } )
                        ->select(
                            'product_details.id',
                            'product_details.product_id',
                            'product_details.user_id',
                            'product_details.vendor_id',
                            'product_details.status',
                            'product_details.reason',
                            'product_details.uniqid',
                            'product_details.created_at',
                            'product_details.updated_at',
                            DB::raw( 'product_details.tenant_id as stored_tenant_id' )
                        )
                        ->groupBy( 'product_details.id' );
                } else {
                    $query->select(
                        'product_details.id',
                        'product_details.product_id',
                        'product_details.user_id',
                        'product_details.vendor_id',
                        'product_details.status',
                        'product_details.reason',
                        'product_details.uniqid',
                        'product_details.created_at',
                        'product_details.updated_at',
                        DB::raw( 'product_details.tenant_id as stored_tenant_id' )
                    );
                }

                if ( $orderId ) {
                    $query->where( 'product_details.id', 'like', "%{$orderId}%" );
                }

                $query->orderBy( 'product_details.created_at', 'desc' );

                $tenantResults = $query->get();

                $tenantResults->transform( function ( $item ) use ( $tenant ) {
                    $item->dropshipper_tenant_id   = $tenant->id;
                    $item->dropshipper_tenant_name = $tenant->company_name;
                    $item->tenant_id               = $tenant->id;
                    $item->tenant_name             = $tenant->company_name;
                    $item->tenant_owner            = $tenant->owner_name;

                    return $item;
                } );

                $allProductDetails = $allProductDetails->merge( $tenantResults );
            } catch ( \Exception $e ) {
                \Log::warning( "Failed to query dropshipper tenant {$tenant->id}: " . $e->getMessage() );
                continue;
            } finally {
                DB::setDefaultConnection( 'mysql' );
            }
        }

        $productDetails = collect( $allProductDetails )->map( function ( $productDetail ) {
            $storedTenantId      = $productDetail->stored_tenant_id ?? null;
            $dropshipperTenantId = $productDetail->dropshipper_tenant_id ?? null;

            if ( $storedTenantId && isset( $productDetail->product_id ) ) {
                $merchantConnection = $this->configureTenantConnection( $storedTenantId );

                if ( $merchantConnection ) {
                    $product = Product::on( $merchantConnection )
                        ->select( 'id', 'name', 'selling_price', 'image' )
                        ->find( $productDetail->product_id );

                    if ( $product ) {
                        $product->load( 'productImage' );
                    }
                    $productDetail->product = $product;
                }
            }

            if ( $dropshipperTenantId ) {
                $dropshipperConnection = $this->configureTenantConnection( $dropshipperTenantId );

                if ( $dropshipperConnection ) {
                    if ( isset( $productDetail->vendor_id ) ) {
                        $productDetail->vendor = User::on( $dropshipperConnection )
                            ->select( 'id', 'name' )
                            ->find( $productDetail->vendor_id );
                    }

                    if ( isset( $productDetail->user_id ) ) {
                        $productDetail->affiliator = User::on( $dropshipperConnection )
                            ->select( 'id', 'name' )
                            ->find( $productDetail->user_id );
                    }
                }
            }

            return $productDetail;
        } );

        $productDetails = $productDetails->sortByDesc( function ( $productDetail ) {
            return $productDetail->created_at ?? '';
        } )->values();

        $page    = (int) request()->get( 'page', 1 );
        $perPage = 10;
        $offset  = ( $page - 1 ) * $perPage;

        $paginatedProductDetails = $productDetails->slice( $offset, $perPage );
        $total                   = $productDetails->count();
        $lastPage                = (int) max( 1, ceil( $total / $perPage ) );

        $path        = request()->url();
        $queryParams = request()->query();
        $buildUrl    = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;

            return $path . '?' . http_build_query( $queryParams );
        };

        return [
            'data'            => $paginatedProductDetails->values(),
            'current_page'    => $page,
            'per_page'        => $perPage,
            'total'           => $total,
            'last_page'       => $lastPage,
            'from'            => $total ? $offset + 1 : null,
            'to'              => min( $offset + $perPage, $total ),
            'path'            => $path,
            'first_page_url'  => $buildUrl( 1 ),
            'last_page_url'   => $total ? $buildUrl( $lastPage ) : null,
            'prev_page_url'   => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url'   => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
        ];
    }

    private function configureTenantConnection( string $tenantId ): ?string {
        $tenant = Tenant::on( 'mysql' )->find( $tenantId );
        if ( !$tenant ) {
            return null;
        }

        $connectionName = 'tenant_' . $tenant->id;

        config( [
            'database.connections.' . $connectionName => [
                'driver'   => 'mysql',
                'host'     => config( 'database.connections.mysql.host' ),
                'port'     => config( 'database.connections.mysql.port' ),
                'database' => 'affsellc_' . $tenant->id,
                'username' => config( 'database.connections.mysql.username' ),
                'password' => config( 'database.connections.mysql.password' ),
                'charset'  => 'utf8mb4',
                'collation'=> 'utf8mb4_unicode_ci',
                'strict'   => false,
            ],
        ] );
        DB::purge( $connectionName );

        return $connectionName;
    }

    function RequestRejected() {

        // Check if the user is an employee and has permission
        if ( Auth::user()->role_type === 'employee' && !tenantPermission( 'reject_request' ) ) {
            return $this->employeeMessage();
        }

        return response()->json( [
            'status'  => 200,
            'product' => $this->dropshipperProductRequests( '3' ),
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

            // Remove tenant context attributes that were added by getSingleFromTenant
            // These are not actual database columns and will cause errors if saved
            unset($data->tenant_domain);
            unset($data->tenant_name);
            unset($data->tenant_id);
            // Note: tenant_id is a real column, so we keep it

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
        $status = request( 'status' );
        $map    = [
            'pending'  => '2',
            'rejected' => '3',
            'active'   => '1',
        ];

        $count = isset( $map[$status] ) ? $this->dropshipperProductRequestCount( $map[$status] ) : 0;

        return response()->json( [
            'status' => 200,
            'count'  => $count,
        ] );
    }

    private function dropshipperProductRequestCount( string $status ): int {
        $total   = 0;
        $tenants = Tenant::on( 'mysql' )->where( 'type', 'dropshipper' )->get();

        foreach ( $tenants as $tenant ) {
            try {
                $connectionName = $this->configureTenantConnection( $tenant->id );
                if ( !$connectionName ) {
                    continue;
                }

                $total += DB::connection( $connectionName )->table( 'product_details' )
                    ->where( 'tenant_id', tenant()->id )
                    ->where( 'status', $status )
                    ->count();
            } catch ( \Exception $e ) {
                \Log::warning( "Failed to count dropshipper tenant {$tenant->id}: " . $e->getMessage() );
                continue;
            } finally {
                DB::setDefaultConnection( 'mysql' );
            }
        }

        return $total;
    }

    function membershipexpireactiveproductCount() {
        $expire_request_count = ProductDetails::query()
            ->where( ['vendor_id' => auth()->id(), 'status' => 1] )
            ->whereHas( 'affiliator', function ( $query ) {
                $query->withCount( ['affiliatoractiveproducts' => function ( $query ) {
                    $query->where( 'status', 1 );
                }] )
                    ->whereCentralSubscription( false );
            } )
            ->count();

        return response()->json( [
            'status'               => 200,
            'expire_request_count' => $expire_request_count,
        ] );
    }
}
