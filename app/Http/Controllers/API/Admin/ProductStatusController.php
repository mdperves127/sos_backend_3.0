<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductDetails;
use App\Models\User;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use App\Models\Tenant;
use App\Services\CrossTenantQueryService;

class ProductStatusController extends Controller
{

    public function AdminRequestPending()
    {
        if(checkpermission('pending-request') != 1){
            return $this->permissionmessage();
        }
        $search = request('search');
        $product = ProductDetails::query()
            ->with(['vendor', 'affiliator', 'product'])
            ->where('status', '2')
            ->when($search != '', function ($query) use ($search) {
                $query->whereHas('product', function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%');
                })
                    ->orWhere('uniqid', 'like', '%' . $search . '%');
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return response()->json([
            'status' => 200,
            'product' => $product,
        ]);
    }


    public function AdminRequestActive()
    {
        if(checkpermission('active-request') != 1){
            return $this->permissionmessage();
        }

        $search = request('search');
        $product = ProductDetails::query()
            ->with(['vendor:id,name', 'affiliator:id,name', 'product:id,name,image,discount_rate',])
            ->where('status', '1')
            ->when($search != '', function ($query) use ($search) {
                $query->whereHas('product', function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%');
                })
                    ->orWhere('uniqid', 'like', '%' . $search . '%');
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return response()->json([
            'status' => 200,
            'product' => $product,
        ]);
    }


    function AdminRequestAll()
    {
        // if(checkpermission('all-request') != 1){
        //     return $this->permissionmessage();
        // }

        $search = request('search');

        // Query ProductDetails from all merchant tenant databases
        $allProductDetails = CrossTenantQueryService::queryAllTenants(
            ProductDetails::class,
            function ( $query ) use ( $search ) {
                // Handle search functionality - join with products table for search
                if ( $search ) {
                    $query->leftJoin( 'products', 'product_details.product_id', '=', 'products.id' )
                          ->where( function ( $q ) use ( $search ) {
                              $q->where( 'products.name', 'like', "%{$search}%" )
                                ->orWhere( 'products.uniqid', 'like', "%{$search}%" );
                          } )
                          ->select( 'product_details.*' )
                          ->groupBy( 'product_details.id' );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $productDetails = collect( $allProductDetails )->map( function ( $productDetail ) {
            // Load relationships manually for each product detail
            if ( isset( $productDetail->product_id ) && isset( $productDetail->tenant_id ) ) {
                $tenant = Tenant::find( $productDetail->tenant_id );
                if ( $tenant ) {
                    $connectionName = 'tenant_' . $tenant->id;
                    $databaseName = 'sosanik_tenant_' . $tenant->id;

                    // Configure connection using the same method as CrossTenantQueryService
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
                        ]
                    ]);
                    DB::purge( $connectionName );

                    // Load product
                    $product = Product::on( $connectionName )->select( 'id', 'name', 'image', 'discount_rate' )->find( $productDetail->product_id );
                    $productDetail->product = $product;

                    // Load vendor
                    if ( isset( $productDetail->vendor_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $productDetail->vendor_id );
                        $productDetail->vendor = $vendor;
                    }

                    // Load affiliator (user_id is the affiliator)
                    if ( isset( $productDetail->user_id ) ) {
                        $affiliator = User::on( $connectionName )->select( 'id', 'name' )->find( $productDetail->user_id );
                        $productDetail->affiliator = $affiliator;
                    }
                }
            }

            return $productDetail;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $productDetails = $productDetails->sortByDesc( function ( $productDetail ) {
            return $productDetail->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedProductDetails = $productDetails->slice( $offset, $perPage );
        $lastPage = ceil( $productDetails->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedProductDetails->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $productDetails->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $productDetails->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $buildUrl( $lastPage ),
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
        ];

        return response()->json([
            'status' => 200,
            'product' => $response,
        ]);

    }


    function RequestRejected()
    {
        if(checkpermission('rejected-request') != 1){
            return $this->permissionmessage();
        }
        $search = request('search');
        $product = ProductDetails::query()
            ->with(['vendor', 'affiliator', 'product'])
            ->where('status', '3')
            ->when($search != '', function ($query) use ($search) {
                $query->whereHas('product', function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%');
                })
                    ->orWhere('uniqid', 'like', '%' . $search . '%');
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return response()->json([
            'status' => 200,
            'product' => $product,
        ]);
    }


    function RequestUpdate(Request $request, $tenant_id, $id)
    {
        // Get tenant from request
        $tenant = Tenant::where('id',$tenant_id)->first();
        if (!$tenant) {
            return response()->json([
                'status' => 404,
                'message' => 'Tenant not found',
            ]);
        }

        // Get ProductDetails from tenant's database
        $data = CrossTenantQueryService::getSingleFromTenant(
            $tenant_id,
            ProductDetails::class,
            function ($query) use ($id) {
                $query->where('id', $id);
            }
        );

        if (!$data) {
            return response()->json([
                'status' => 404,
                'message' => 'ProductDetails not found',
            ]);
        }

        // Remove tenant context attributes that were added by getSingleFromTenant
        // These are not actual database columns and will cause errors if saved
        unset($data->tenant_id);
        unset($data->tenant_domain);
        unset($data->tenant_name);

        // Update the ProductDetails
        $data->status = $request->status;
        $data->reason = $request->reason;
        $data->save();
        return response()->json([
            'status' => 200,
            'message' => 'updated successfully',
        ]);
    }


    function AdminRequestView($id)
    {
        $product = ProductDetails::with(['vendor', 'affiliator', 'product' => function ($query) {
            $query->with('productImage');
        }])->find($id);



        return response()->json([
            'status' => 200,
            'product' => $product,
        ]);
    }


    public function AdminRequestBalances()
    {
        $user = User::where('balance_status', 0)->get();
        return response()->json($user);
    }


    public function AdminRequestBalanceActive()
    {
        $user = User::where('balance_status', 1)->get();
        return response()->json($user);
    }
}
