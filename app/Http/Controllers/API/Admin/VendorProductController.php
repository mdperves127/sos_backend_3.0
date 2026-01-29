<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductEditRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\PendingProduct;
use App\Services\CrossTenantQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorProductController extends Controller
{
    function index()
    {
        // if(checkpermission('edit-products') != 1){
        //     return $this->permissionmessage();
        // }

        // Query Products from all merchant tenant databases with pendingproduct filter
        $allProducts = CrossTenantQueryService::queryAllTenants(
            Product::class,
            function ( $query ) {
                // Join with pending_products table to filter products that have pendingproduct
                $query->join('pending_products', 'products.id', '=', 'pending_products.product_id')
                      ->select('products.*')
                      ->orderBy('products.created_at', 'desc');
            }
        );

        // Load relationships for each product
        $products = collect( $allProducts )->map( function ( $product ) {
            // Get tenant from the product
            if ( isset( $product->tenant_id ) ) {
                $tenant = Tenant::on('mysql')->find( $product->tenant_id );
                if ( $tenant ) {
                    $connectionName = 'tenant_' . $tenant->id;
                    $databaseName = 'storebz_tenant_' . $tenant->id;

                    // Configure connection
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

                    // Load vendor relationship
                    if ( isset( $product->user_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $product->user_id );
                        $product->vendor = $vendor;
                    }

                    // Load pendingproduct relationship
                    if ( isset( $product->id ) ) {
                        $pendingProduct = PendingProduct::on( $connectionName )
                            ->select( 'id', 'product_id', 'is_reject' )
                            ->where( 'product_id', $product->id )
                            ->first();
                        $product->pendingproduct = $pendingProduct;
                    }
                }
            }
            return $product;
        } )->values();

        // Manual pagination
        $page = request()->get('page', 1);
        $perPage = 10;
        $offset = ($page - 1) * $perPage;
        $paginatedProducts = $products->slice($offset, $perPage);
        $lastPage = ceil($products->count() / $perPage);

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build links array
        $links = [];
        $links[] = [
            'url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'label' => '&laquo; Previous',
            'active' => false
        ];

        for ( $i = 1; $i <= $lastPage; $i++ ) {
            $links[] = [
                'url' => $buildUrl( $i ),
                'label' => (string) $i,
                'active' => $i == $page
            ];
        }

        $links[] = [
            'url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'label' => 'Next &raquo;',
            'active' => false
        ];

        return response()->json([
            'product' => $paginatedProducts->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $products->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min($offset + $perPage, $products->count()),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $buildUrl( $lastPage ),
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'links' => $links,
        ]);
    }


    function productview(int $id)
    {
        if(checkpermission('edit-products') != 1){
            return $this->permissionmessage();
        }

        $product = Product::query()
            ->where('id', $id)
            ->withWhereHas('pendingproduct')
            ->first();
        if (!$product) {
            return responsejson('Not found', 'fail');
        }

        return $this->response($product);
    }

    function productstatus(ProductEditRequest $request, int $id)
    {
        $product = Product::query()
            ->where('id', $id)
            ->withWhereHas('pendingproduct')
            ->first();

        if (!$product) {
            return responsejson('Not found', 'fail');
        }

        $data =  $product->pendingproduct;

        if (request('status') == 1) {
            $data->is_reject = 1;
            $data->reason = request('reason');
            $data->save();
        }
        if (request('status') == 2) {
            if($data->short_description != ''){
                $product->short_description = $data->short_description;
            }
            if($data->long_description != ''){
                $product->long_description = $data->long_description;
            }

            if($data->specifications != ''){
                $product->specifications = $data->specifications;
            }

            if($data->image != ''){
                $product->image = $data->image;
            }
            if($data->images != ''){

                foreach($data->images as $img){
                    ProductImage::create([
                        'product_id'=>$product->id,
                        'image'=>$img
                    ]);
                }

            }
            $product->save();

            $data->delete();
        }

        if(request('status') == 0){
            $data->is_reject = 0;
            $data->reason = '';
            $data->save();
        }


        return $this->response('Updated successfully');
    }
}
