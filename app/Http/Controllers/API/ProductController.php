<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Models\ProductImage;
use App\Models\ProductRating;
use App\Models\Size;
use App\Models\Subcategory;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Tenant;
use App\Notifications\VendorProductStatusNotification;
use App\Services\CrossTenantQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductController extends Controller {
    public function ProductIndex() {

        // if ( !in_array( request( 'status' ), ['pending', 'active', 'rejected'] ) ) {
        //     if ( checkpermission( 'all-products' ) != 1 ) {
        //         return $this->permissionmessage();
        //     }
        // }

        // if ( request( 'status' ) == 'pending' ) {
        //     if ( checkpermission( 'pending-products' ) != 1 ) {
        //         return $this->permissionmessage();
        //     }
        // }

        // if ( request( 'status' ) == 'active' ) {
        //     if ( checkpermission( 'active-products' ) != 1 ) {
        //         return $this->permissionmessage();
        //     }
        // }

        // if ( request( 'status' ) == 'rejected' ) {
        //     if ( checkpermission( 'rejected-product' ) != 1 ) {
        //         return $this->permissionmessage();
        //     }
        // }

        $status = request( 'status' );
        $search = request( 'search' );

        // Query Products from all merchant tenant databases
        $allProducts = CrossTenantQueryService::queryAllTenants(
            Product::class,
            function ( $query ) use ( $status, $search ) {
                // Filter by status
                if ( $status == 'pending' ) {
                    $query->where( 'status', 'pending' );
                } elseif ( $status == 'active' ) {
                    $query->where( 'status', 'active' );
                } elseif ( $status == 'rejected' ) {
                    $query->where( 'status', 'rejected' );
                }

                // Handle search functionality
                if ( $search ) {
                    $query->where( function ( $q ) use ( $search ) {
                        $q->where( 'name', 'like', "%{$search}%" )
                          ->orWhere( 'uniqid', 'like', "%{$search}%" );
                    } );
                }

                // Order by latest
                $query->orderBy( 'created_at', 'desc' );
            }
        );

        // Convert stdClass objects and load relationships
        $products = collect( $allProducts )->map( function ( $product ) {
            // Load vendor relationship manually
            if ( isset( $product->user_id ) && isset( $product->tenant_id ) ) {
                $tenant = Tenant::find( $product->tenant_id );
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

                    // Load vendor (user_id is the vendor)
                    if ( isset( $product->user_id ) ) {
                        $vendor = User::on( $connectionName )->select( 'id', 'name' )->find( $product->user_id );
                        $product->vendor = $vendor;
                    }
                }
            }

            return $product;
        } );

        // Sort by latest (created_at desc) - already sorted in query but ensure consistency
        $products = $products->sortByDesc( function ( $product ) {
            return $product->created_at ?? '';
        } )->values();

        // Re-paginate after processing
        $page = request()->get( 'page', 1 );
        $perPage = 10;
        $offset = ( $page - 1 ) * $perPage;
        $paginatedProducts = $products->slice( $offset, $perPage );
        $lastPage = ceil( $products->count() / $perPage );

        // Build pagination URLs
        $path = request()->url();
        $queryParams = request()->query();
        $buildUrl = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;
            return $path . '?' . http_build_query( $queryParams );
        };

        // Build pagination response
        $response = [
            'data' => $paginatedProducts->values(),
            'current_page' => (int) $page,
            'per_page' => $perPage,
            'total' => $products->count(),
            'last_page' => $lastPage,
            'from' => $offset + 1,
            'to' => min( $offset + $perPage, $products->count() ),
            'path' => $path,
            'first_page_url' => $buildUrl( 1 ),
            'last_page_url' => $buildUrl( $lastPage ),
            'prev_page_url' => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'next_page_url' => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
        ];

        return response()->json( [
            'status'  => 200,
            'product' => $response,
        ] );
    }

    function catecoryToSubcategory( $id ) {
        $category = Category::where( [
            'status' => 'active',
            'id'     => $id,
        ] )->first();

        if ( $category ) {
            return response()->json( [
                'status'  => 200,
                'message' => Subcategory::where( [
                    'category_id' => $category->id,
                    'status'      => 'active',
                ] )->latest()->get(),
            ] );
        } else {
            return response()->json( [
                'status'  => 400,
                'message' => [],
            ] );
        }
    }

    function updateStatus( Request $request, $tenant_id, $id ) {

        $validator = Validator::make(
            $request->all(),
            [
                'status'           => 'required',
                'rejected_details' => 'nullable',
            ]
        );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        } else {
            // Get tenant from request
            $tenant = Tenant::where('id', $tenant_id)->first();

            if (!$tenant) {
                return response()->json([
                    'status' => 404,
                    'message' => 'Tenant not found',
                ]);
            }

            // Configure tenant connection
            $connectionName = 'tenant_' . $tenant->id;
            $databaseName = 'sosanik_tenant_' . $tenant->id;

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
            DB::purge($connectionName);

            // Check if Product exists and get user_id for notification
            $product = Product::on($connectionName)->find($id);

            if (!$product) {
                // Restore default connection
                DB::setDefaultConnection('mysql');
                return response()->json([
                    'status' => 404,
                    'message' => 'Product not found',
                ]);
            }

            // Store user_id before update
            $userId = $product->user_id;

            // Update the Product using query builder to avoid attribute issues
            Product::on($connectionName)
                ->where('id', $id)
                ->update([
                    'status' => $request->status,
                    'rejected_details' => $request?->rejected_details ?? '',
                ]);

            // Get vendor user (Product uses user_id for vendor, not vendor_id)
            // Note: tenant database users table doesn't have role_as column
            $user = User::on($connectionName)
                ->where('id', $userId)
                ->first();

            // Get product again for notification (with updated data) before restoring connection
            $updatedProduct = null;
            if ($user) {
                $updatedProduct = Product::on($connectionName)->find($id);
            }

            // Restore default connection
            DB::setDefaultConnection('mysql');

            if ($user && $updatedProduct) {
                Notification::send( $user, new VendorProductStatusNotification( $user, $updatedProduct ) );
            }

            return response()->json( [
                'status'   => 200,
                'messaage' => 'Updated successfully!',
            ] );
        }
    }

    public function VendorProductStore( Request $request ) {
        $validator = Validator::make( $request->all(), [

            'name'           => 'required|unique:products|max:255',
            //  'slug'=>'required',
            'status'         => 'required',
            'category_id'    => 'required',
            'qty'            => 'required',
            'selling_price'  => 'required',
            'original_price' => 'required',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 400,
                'errors' => $validator->messages(),
            ] );
        } else {
            $product                    = new Product;
            $product->category_id       = $request->input( 'category_id' );
            $product->subcategory_id    = $request->input( 'subcategory_id' );
            $product->brand_id          = $request->input( 'brand_id' );
            $product->user_id           = Auth::user()->id;
            $product->name              = $request->input( 'name' );
            $product->slug              = Str::slug( $request->name );
            $product->short_description = $request->input( 'short_description' );
            $product->long_description  = $request->input( 'long_description' );
            $product->selling_price     = $request->input( 'selling_price' );
            $product->original_price    = $request->input( 'original_price' );
            $product->qty               = $request->input( 'qty' );
            $product->status            = $request->input( 'status' );
            $product->meta_title        = $request->input( 'meta_title' );
            $product->meta_keyword      = $request->input( 'meta_keyword' );
            $product->meta_description  = $request->input( 'meta_description' );
            // $product->specification      = json_encode($request->specification);
            // $product->specification_ans  = json_encode($request->specification_ans);
            $product->request       = 0;
            $product->tags          = json_encode( $request->tags );
            $product->discount_type = $request->input( 'discount_type' );
            $product->discount_rate = $request->input( 'discount_rate' );

            $product->save();

            $product->colors()->attach( $request->colors );
            $product->sizes()->attach( $request->sizes );

            $productId = $product->id;
            $images    = $request->file( 'images' );
            foreach ( $images as $image ) {
                // image01 upload
                $name       = time() . '-' . $image->getClientOriginalName();
                $uploadpath = 'uploads/product/';
                $image->move( $uploadpath, $name );
                $imageUrl = $uploadpath . $name;

                $proimage             = new ProductImage();
                $proimage->product_id = $productId;
                $proimage->image      = $imageUrl;
                $proimage->save();
            }

            return response()->json( [
                'status'  => 200,
                'message' => 'Product Added Sucessfully',
            ] );
        }
    }

    public function ProductEdit( $tenant_id,$id ) {

        // Get tenant from request
        $tenant = Tenant::where('id', $tenant_id)->first();

        if (!$tenant) {
            return response()->json([
                'status' => 404,
                'message' => 'Tenant not found',
            ]);
        }

        // Configure tenant connection
        $connectionName = 'tenant_' . $tenant->id;
        $databaseName = 'sosanik_tenant_' . $tenant->id;

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
        DB::purge($connectionName);

        // Query product from tenant database
        $vendorproduct = Product::on($connectionName)
            ->with( 'category', 'subcategory', 'brand', 'productImage', 'productdetails', 'vendor', 'productrating.affiliate:id,name,image' )
            ->withAvg( 'productrating', 'rating' )
            ->find( $id );

        if ( !$vendorproduct ) {
            // Restore default connection
            DB::setDefaultConnection('mysql');
            return response()->json( [
                'status'  => 404,
                'message' => 'No Product Id Found',
            ] );
        }

        if ( $vendorproduct->status == 'active' ) {
            if ( ( checkpermission( 'all-products' ) != 1 ) ) {

                if ( checkpermission( 'active-products' ) != 1 ) {
                    DB::setDefaultConnection('mysql');
                    return $this->permissionmessage();
                }
            }
        }

        if ( $vendorproduct->status == 'pending' ) {
            if ( ( checkpermission( 'all-products' ) != 1 ) ) {

                if ( checkpermission( 'pending-products' ) != 1 ) {
                    DB::setDefaultConnection('mysql');
                    return $this->permissionmessage();
                }
            }
        }

        if ( $vendorproduct->status == 'rejected' ) {
            if ( ( checkpermission( 'all-products' ) != 1 ) ) {

                if ( checkpermission( 'rejected-product' ) != 1 ) {
                    DB::setDefaultConnection('mysql');
                    return $this->permissionmessage();
                }
            }
        }

        $response = response()->json( [
            'status'               => 200,
            'product'              => $vendorproduct,
            'vendor_all_color'     => Color::on($connectionName)->where( ['user_id' => $vendorproduct->user_id, 'status' => 'active'] )->get(),
            'vendor_all_size'      => Size::on($connectionName)->where( ['user_id' => $vendorproduct->user_id, 'status' => 'active'] )->get(),
            'all_category_list'    => Category::on($connectionName)->where( 'status', 'active' )->get(),
            'all_subcategory_list' => Subcategory::on($connectionName)->where( 'status', 'active' )->get(),
            'all_brand_list'       => Brand::on($connectionName)->where( 'status', 'active' )->get(),
            'suppliers'            => Supplier::on($connectionName)->where( ['vendor_id' => $vendorproduct->vendor_id, 'status' => 'active'] )->get(),
            'warehouse'            => Warehouse::on($connectionName)->where( ['vendor_id' => $vendorproduct->vendor_id, 'status' => 'active'] )->get(),
        ] );

        // Restore default connection
        DB::setDefaultConnection('mysql');

        return $response;
    }

    public function destroy( $id ) {
        $product = Product::find( $id );

        // $image_path = app_path("uploads/product/{$product->image}");

        // if (File::exists($image_path)) {
        //     unlink($image_path);
        // }
        if ( $product ) {
            $product->delete();
            return response()->json( [
                'status'  => 200,
                'message' => 'Product Deleted Successfully',
            ] );
        } else {
            return response()->json( [
                'status'  => 404,
                'message' => 'No Product ID Found',
            ] );
        }
    }

    public function UpdateProduct( Request $request, $id ) {
        $validator = Validator::make( $request->all(), [
            'name'           => 'required|unique:products|max:255',
            'status'         => 'required',
            'category_id'    => 'required',
            'image'          => 'required',
            'qty'            => 'required',
            'selling_price'  => 'required',
            'original_price' => 'required',

        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status' => 422,
                'errors' => $validator->messages(),
            ] );
        } else {
            $product = Product::find( $id );
            if ( $product ) {

                $product->category_id    = $request->input( 'category_id' );
                $product->subcategory_id = $request->input( 'subcategory_id' );
                $product->brand_id       = $request->input( 'brand_id' );
                $product->user_id        = $request->input( 'user_id' );
                // $product->user_id=Auth::user()->id;
                $product->slug              = slugUpdate( Product::class, $request->name, $id );
                $product->name              = $request->input( 'name' );
                $product->short_description = $request->input( 'short_description' );
                $product->long_description  = $request->input( 'long_description' );
                $product->selling_price     = $request->input( 'selling_price' );
                $product->original_price    = $request->input( 'original_price' );
                $product->qty               = $request->input( 'qty' );
                // $product->status = $request->input('status');
                $product->meta_title       = $request->input( 'meta_title' );
                $product->meta_keyword     = $request->input( 'meta_keyword' );
                $product->meta_description = $request->input( 'meta_description' );
                // $product->product_color = $request->input('product_color');
                // $product->product_size = $request->input('product_size');
                $product->tags           = $request->input( 'tags' );
                $product->commision_type = $request->input( 'commision_type' );

                $product->colors()->attach( $request->colors );
                $product->sizes()->attach( $request->sizes );

                if ( $request->hasFile( 'image' ) ) {
                    $path = $product->image;
                    if ( File::exists( $path ) ) {
                        File::delete( $path );
                    }
                    $file      = $request->file( 'image' );
                    $extension = $file->getClientOriginalExtension();
                    $filename  = time() . '.' . $extension;
                    $file->move( 'uploads/product/', $filename );
                    $product->image = 'uploads/product/' . $filename;
                }

                $productId     = $product->id;
                $update_images = ProductImage::where( 'product_id', $productId )->get();
                $images        = $request->file( 'image' );
                if ( $images ) {
                    foreach ( $images as $image ) {
                        // image01 upload
                        $name       = time() . '-' . $image->getClientOriginalName();
                        $uploadpath = 'public/backend/product/';
                        $image->move( $uploadpath, $name );
                        $imageUrl = $uploadpath . $name;

                        $proimage             = new ProductImage();
                        $proimage->product_id = $productId;
                        $proimage->image      = $imageUrl;
                        $proimage->save();
                    }
                } else {
                    foreach ( $update_images as $update_image ) {
                        $uimage              = $update_image->image;
                        $update_image->image = $uimage;
                        $update_image->save();
                    }
                }

                $product->update();

                return response()->json( [
                    'status'  => 200,
                    'message' => 'Product Updated Successfully',
                ] );
            } else {
                return response()->json( [
                    'status'  => 404,
                    'message' => 'Product Not Found',
                ] );
            }
        }
    }

    public function AllCategory() {
        $category = Category::when( request( 'search' ), fn( $q, $name ) => $q->where( 'name', 'like', "%{$name}%" ) )
            ->latest()->paginate( 15 );
        return response()->json( [
            'status'   => 200,
            'category' => $category,
        ] );
    }

    public function AllBrand() {
        $brand = Brand::all();
        return response()->json( [
            'status' => 200,
            'brand'  => $brand,
        ] );
    }

    public function approval( $id ) {
        $product = ProductDetails::find( $id );
        if ( $product->status == '2' ) {
            $product->status = '1';
            $product->save();
            return response()->json( [
                'status'  => 200,
                'message' => 'Product is Accepted ',
            ] );
        } else {
            return response()->json( [
                'status'  => 401,
                'message' => 'Product is Request Accepted',
            ] );
        }
    }

    public function reject( $id ) {
        $product = ProductDetails::find( $id );
        if ( $product->status == '2' ) {
            $product->status = '3';
            $product->save();
            return response()->json( [
                'status'  => 200,
                'message' => 'Product is Reject ',
            ] );
        } else {
            return response()->json( [
                'status'  => 401,
                'message' => 'Product is Request Accepted',
            ] );
        }
    }

    public function Accepted( $id ) {
        $product = ProductDetails::find( $id );
        if ( $product->status == 'pending' ) {
            $product->status = 'active';
            $product->save();
            return response()->json( [
                'status'  => 200,
                'message' => 'Product is active ',
            ] );
        } else {
            return response()->json( [
                'status'  => 401,
                'message' => 'Product is Inactive',
            ] );
        }
    }

    public function producteditrequest() {
        return "ok";
    }

    public function productRating() {
        $ratings = ProductRating::with( 'affiliate:id,name', 'product:id,name' )->latest()->get();

        return response( [
            'status'  => 200,
            'ratings' => $ratings,
        ] );

    }

    public function removeRating( $id ) {
        $ratings = ProductRating::find( $id );

        if ( !$ratings ) {
            return response( [
                'status'  => 404,
                'message' => "Not found !",
            ] );
        }

        $ratings->delete();
        return response( [
            'status'  => 200,
            'message' => "Successfully removed !",
        ] );
    }
}
