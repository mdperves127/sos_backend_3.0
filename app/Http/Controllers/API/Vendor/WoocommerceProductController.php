<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WoocommerceCredential;
use Exception;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WoocommerceProductController extends Controller {
    protected Client $client;

    public function __construct( Client $client ) {
        $this->client = $client;
    }

    private function wcCredential(): ?array {
        $credential = WoocommerceCredential::where( 'vendor_id', vendorId() )->first();

        if ( !$credential ) {
            return null;
        }

        return [
            'wc_key'    => $credential->wc_key,
            'wc_secret' => $credential->wc_secret,
            'wc_url'    => $credential->wc_url,
        ];
    }

    public function index(): JsonResponse {

        try {

            $credentials = $this->wcCredential();
            if ( !$credentials ) {
                return response()->json( ['error' => 'No credentials found for this vendor.'], 404 );
            }

            // Initialize pagination parameters
            $perPage = 2;
            $page    = request()->get( 'page', 1 ); // Default to page 1 if not provided

            // Make the API request with pagination
            $response = $this->client->request( 'GET', $credentials['wc_url'] . '/wp-json/wc/v3/products', [
                'auth'  => [$credentials['wc_key'], $credentials['wc_secret']],
                'query' => [
                    'per_page' => $perPage,
                    'page'     => $page,
                ],
            ] );

            if ( !in_array( $response->getStatusCode(), [200, 201] ) ) {
                return response()->json( ['error' => 'Failed to retrieve orders. Status code: ' . $response->getStatusCode()], $response->getStatusCode() );
            }

            // Parse response data
            $products = json_decode( $response->getBody()->getContents(), true );

            // Total pages and total count (use WooCommerce headers)
            $totalCount = $response->getHeaderLine( 'X-WP-Total' );
            $totalPages = $response->getHeaderLine( 'X-WP-TotalPages' );

            $responseProductFetch = [];
            if ( $totalPages > 1 ) {
                for ( $i = 1; $i <= $totalPages; $i++ ) {
                    $responseProductFetch[] = $this->wcProductFetch( $credentials, $i, 10 );
                }
            }

            $totalProducts = Product::where( 'user_id', vendorId() )->get();

            return response()->json( [
                'status'     => 200,
                'data'       => $totalProducts,
                'data_count' => count( $totalProducts ),
                'message'    => "Successfully synced",
            ] );

        } catch ( Exception $e ) {
            error_log( $e->getMessage() );
            return response()->json( ['error' => 'An error occurred: ' . $e->getMessage()], 500 );
        }

        // ============================ old code ============================
    }

    public function wcProductFetch( $credential, $pageNumber = 1, $perPage = 10 ) {
        $response = $this->client->request( 'GET', $credential['wc_url'] . '/wp-json/wc/v3/products', [
            'auth'  => [$credential['wc_key'], $credential['wc_secret']],
            'query' => [
                'per_page' => $perPage,
                'page'     => $pageNumber,
            ],
        ] );

        if ( !in_array( $response->getStatusCode(), [200, 201] ) ) {
            return response()->json( ['error' => 'Failed to retrieve orders. Status code: ' . $response->getStatusCode()], $response->getStatusCode() );
        }

        $products = json_decode( $response->getBody()->getContents(), true );

        foreach ( $products as $product ) {

            $productNo = 'WC-' . ( $product['id'] ?? '0' );

            $existingProduct = Product::where( 'wc_product_id', $productNo )->first();

            if ( $existingProduct ) {
                error_log( "Order with order_id $productNo already exists, skipping." );
                continue;
            }

            if ( $product['status'] != 'publish' ) {
                continue;
            }

            foreach ( $product['attributes'] as $key => $attr ) {
                if ( $attr['name'] == 'color' ) {
                    foreach ( $attr['options'] as $option ) {
                        $checkColor = Color::where( 'user_id', vendorId() )->where( 'name', $option )->first();

                        if ( !$checkColor ) {
                            $size            = new Size();
                            $size->name      = $option;
                            $size->status    = 'active';
                            $size->user_id   = vendorId();
                            $size->slug      = strtolower( $option );
                            $size->vendor_id = vendorId();
                            $size->save();
                        }
                    }

                }
                if ( $attr['name'] == 'size' ) {
                    foreach ( $attr['options'] as $option ) {
                        $checkSize = Size::where( 'user_id', vendorId() )->where( 'name', $option )->first();

                        if ( !$checkSize ) {
                            $size            = new Size();
                            $size->name      = $option;
                            $size->status    = 'active';
                            $size->user_id   = vendorId();
                            $size->slug      = strtolower( $option );
                            $size->vendor_id = vendorId();
                            $size->save();
                        }
                    }
                }
                if ( $attr['name'] == 'unit' ) {
                    foreach ( $attr['options'] as $option ) {
                        $checkSize = Unit::where( 'user_id', vendorId() )->where( 'unit_name', $option )->first();

                        if ( !$checkSize ) {
                            $size            = new Size();
                            $size->unit_name = $option;
                            $size->status    = 'active';
                            $size->user_id   = vendorId();
                            $size->unit_slug = strtolower( $option );
                            $size->vendor_id = vendorId();
                            $size->save();
                        }
                    }
                }
            }

            $categoryId = '';

            if ( is_array( $product['categories'] ) && count( $product['categories'] ) > 0 ) {
                if ( $product['categories'][0] ) {
                    $categoryName = $product['categories'][0]['name'];
                    $categorySlug = $product['categories'][0]['slug'];

                    $category = Category::where( 'name', ucwords( $categoryName ) )->where( 'slug', strtolower( $categorySlug ) )->first();

                    if ( !$category ) {
                        $category         = new Category();
                        $category->name   = ucwords( $categoryName );
                        $category->slug   = strtolower( $categorySlug );
                        $category->status = 'active';
                        $category->save();
                    }
                    $categoryId = $category->id;
                }
            }

            $brandId = '';

            $brand = Brand::where( 'name', 'Woocommerce Brand' )->where( 'slug', 'woocommerce_brand' )->first();

            if ( !$brand ) {
                $brand             = new Brand();
                $brand->name       = "Woocommerce Brand";
                $brand->slug       = 'woocommerce_brand';
                $brand->user_id    = 1;
                $brand->created_by = 'admin';
                $brand->status     = 'active';
                $brand->save();
            }
            $brandId = $brand->id;

            $warehouse = '';

            $warehouse = Warehouse::where( 'vendor_id', vendorId() )->where( 'name', 'Woocommerce' )->where( 'slug', 'woocommerce' )->first();

            if ( $warehouse ) {
                $warehouse              = new Warehouse();
                $warehouse->user_id     = Auth::id();
                $warehouse->name        = "Woocommerce";
                $warehouse->slug        = 'woocommerce';
                $warehouse->description = 'Woocommerce';
                $warehouse->status      = 'active';
                $warehouse->vendor_id   = vendorId();
                $warehouse->save();
            }
            $warehouse = $warehouse->id;

            $addProduct                = new Product();
            $addProduct->product_type  = 'woocommerce';
            $addProduct->wc_product_id = $productNo;
            $addProduct->category_id   = $categoryId;
            $addProduct->brand_id      = $brandId;
            $addProduct->warehouse_id  = $warehouse;
            $addProduct->user_id       = vendorId();
            $addProduct->vendor_id     = vendorId();
            $addProduct->name          = $product['name'];
            $addProduct->slug          = $product['slug'];

            $addProduct->short_description = $product['short_description'];
            $addProduct->sku               = $product['sku'] ?? generateSKU();
            $addProduct->long_description  = $product['description'];

            $addProduct->selling_price  = $product['sale_price'] ?? 0.00;
            $addProduct->original_price = $product['regular_price'] ?? 0.00;

            if ( $product['manage_stock'] ) {
                $addProduct->qty = $product['stock_quantity'];
            } else {
                $addProduct->qty = 0;
            }

            $addProduct->image      = $product['slug'];
            $addProduct->status     = 'active';
            $addProduct->meta_title = $product['name'];

            if ( is_array( $product['tags'] ) && count( $product['tags'] ) > 0 ) {
                $addProduct->tags         = collect( $product['tags'] )->pluck( 'name' );
                $addProduct->meta_keyword = collect( $product['tags'] )->pluck( 'name' );
            } else {
                $addProduct->tags         = null;
                $addProduct->meta_keyword = null;
            }

            $addProduct->meta_description            = $product['short_description'];
            $addProduct->specification               = null;
            $addProduct->specification_ans           = null;
            $addProduct->commision_type              = null;
            $addProduct->request                     = null;
            $addProduct->user_type                   = null;
            $addProduct->variants                    = null;
            $addProduct->rejected_details            = null;
            $addProduct->selling_type                = 'single';
            $addProduct->selling_details             = null;
            $addProduct->advance_payment             = 0.00;
            $addProduct->single_advance_payment_type = null;
            $addProduct->is_connect_bulk_single      = 0;
            $addProduct->specifications              = [];
            $addProduct->uniqid                      = uniqid();
            $addProduct->distributor_price           = null;
            $addProduct->alert_qty                   = 0;
            $addProduct->pre_order                   = (string) 0;

            $checkSuplier = Supplier::where( 'supplier_name', 'Woocommerce Supplier' )->where( 'vendor_id', vendorId() )->first();

            if ( $checkSuplier ) {

                $addProduct->supplier_id = $checkSuplier->id;
            } else {
                $supplier                = new Supplier();
                $supplier->supplier_name = 'Woocommerce Supplier';
                $supplier->supplier_slug = 'woocommerce-supplier';
                $supplier->supplier_id   = generateRandomString( 8 );
                $supplier->business_name = 'Woocommerce Supplier';
                $supplier->phone         = '';
                $supplier->email         = '';
                $supplier->address       = '';
                $supplier->description   = '';
                $supplier->status        = 'active';
                $supplier->vendor_id     = vendorId();
                $supplier->save();
                $addProduct->supplier_id = $supplier->id;
            }

            $addProduct->discount_type = 'flat';

            if ( isset( $product['regular_price'] ) && $product['regular_price'] != '' && isset( $product['sale_price'] ) && $product['sale_price'] != '' ) {
                $discountRate              = $product['regular_price'] - $product['sale_price'];
                $addProduct->discount_rate = $discountRate;
            } else {
                $addProduct->discount_rate = 0;
            }

            $addProduct->exp_date     = null;
            $addProduct->barcode      = null;
            $addProduct->warranty     = null;
            $addProduct->is_feature   = 0;
            $addProduct->is_affiliate = 0;

            $addProduct->discount_price      = 0;
            $addProduct->discount_percentage = 0;
            $addProduct->save();

            if ( is_array( $product['images'] ) && count( $product['images'] ) > 0 ) {
                foreach ( $product['images'] as $loop => $imageUrl ) {
                    $imagePath = fileUploadFromUrl( $imageUrl['src'], 'uploads/product' );

                    if ( $loop == 0 ) {
                        $addProduct->image = $imagePath;
                        continue;
                    }

                    $productImages             = new ProductImage();
                    $productImages->product_id = $addProduct->id;
                    $productImages->image      = $imagePath;
                    $productImages->save();
                }
            } else {

                $addProduct->image = 'uploads/product/no-image.png';
            }

            $addProduct->save();

        }

    }

}
