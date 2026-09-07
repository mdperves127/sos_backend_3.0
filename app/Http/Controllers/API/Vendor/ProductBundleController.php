<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductBundle;
use App\Models\ProductBundleItem;
use App\Rules\CategoryRule;
use App\Rules\SubCategorydRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Merchant tenant product-bundle CRUD.
 */
class ProductBundleController extends Controller
{
    public function index( Request $request )
    {
        if ( $deny = $this->denyUnlessMerchant() ) {
            return $deny;
        }

        $perPage = (int) $request->input( 'limit', $request->input( 'per_page', 10 ) );
        $perPage = max( 1, min( 100, $perPage < 1 ? 10 : $perPage ) );

        $query = ProductBundle::query()
            ->with( [
                'category:id,name',
                'subcategory:id,name,category_id',
                'items.product:id,name,image,sku,selling_price,discount_price',
            ] )
            ->when( $request->filled( 'status' ), fn ( $q ) => $q->where( 'status', $request->input( 'status' ) ) )
            ->when( $request->filled( 'category_id' ), fn ( $q ) => $q->where( 'category_id', $request->input( 'category_id' ) ) )
            ->when( $request->filled( 'search' ), function ( $q ) use ( $request ) {
                $search = trim( (string) $request->input( 'search' ) );
                $q->where( 'name', 'like', "%{$search}%" );
            } )
            ->latest();

        $bundles = $query->paginate( $perPage )->withQueryString();

        $bundles->getCollection()->transform( function ( ProductBundle $bundle ) {
            return $this->transformBundle( $bundle );
        } );

        return response()->json( [
            'status'  => 200,
            'bundles' => $bundles,
        ] );
    }

    public function create()
    {
        if ( $deny = $this->denyUnlessMerchant() ) {
            return $deny;
        }

        $products = Product::where( 'vendor_id', vendorId() )
            ->where( 'status', 'active' )
            ->select( 'id', 'name', 'image', 'sku', 'selling_price', 'discount_price', 'category_id', 'subcategory_id' )
            ->orderBy( 'name' )
            ->get()
            ->map( function ( Product $product ) {
                return [
                    'id'             => $product->id,
                    'name'           => $product->name,
                    'image'          => $product->image,
                    'sku'            => $product->sku,
                    'selling_price'  => (float) ( $product->discount_price ?: $product->selling_price ?: 0 ),
                    'category_id'    => $product->category_id,
                    'subcategory_id' => $product->subcategory_id,
                ];
            } );

        return response()->json( [
            'status'   => 200,
            'products' => $products,
        ] );
    }

    public function store( Request $request )
    {
        if ( $deny = $this->denyUnlessMerchant() ) {
            return $deny;
        }

        $validator = $this->validator( $request );
        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 400 );
        }

        try {
            $bundle = DB::transaction( function () use ( $request ) {
                $bundle = ProductBundle::create( [
                    'category_id'    => (int) $request->category_id,
                    'subcategory_id' => $request->filled( 'subcategory_id' ) ? (int) $request->subcategory_id : null,
                    'name'           => trim( (string) $request->name ),
                    'bundle_price'   => round( (float) $request->bundle_price, 2 ),
                    'status'         => $request->input( 'status', 'active' ) === 'inactive' ? 'inactive' : 'active',
                ] );

                $this->syncItems( $bundle, $request->input( 'items', [] ) );

                return $bundle->fresh( [
                    'category:id,name',
                    'subcategory:id,name,category_id',
                    'items.product:id,name,image,sku,selling_price,discount_price',
                ] );
            } );

            return response()->json( [
                'status'  => 200,
                'message' => 'Bundle created successfully.',
                'bundle'  => $this->transformBundle( $bundle ),
            ] );
        } catch ( Throwable $e ) {
            return response()->json( [
                'status'  => 400,
                'message' => $e->getMessage(),
            ], 400 );
        }
    }

    public function show( $id )
    {
        if ( $deny = $this->denyUnlessMerchant() ) {
            return $deny;
        }

        $bundle = ProductBundle::with( [
            'category:id,name',
            'subcategory:id,name,category_id',
            'items.product:id,name,image,sku,selling_price,discount_price',
        ] )->find( $id );

        if ( ! $bundle ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Bundle not found',
            ], 404 );
        }

        return response()->json( [
            'status' => 200,
            'bundle' => $this->transformBundle( $bundle ),
        ] );
    }

    public function edit( $id )
    {
        return $this->show( $id );
    }

    public function update( Request $request, $id )
    {
        if ( $deny = $this->denyUnlessMerchant() ) {
            return $deny;
        }

        $bundle = ProductBundle::find( $id );
        if ( ! $bundle ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Bundle not found',
            ], 404 );
        }

        $validator = $this->validator( $request );
        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation error',
                'errors'  => $validator->errors(),
            ], 400 );
        }

        try {
            $bundle = DB::transaction( function () use ( $request, $bundle ) {
                $bundle->update( [
                    'category_id'    => (int) $request->category_id,
                    'subcategory_id' => $request->filled( 'subcategory_id' ) ? (int) $request->subcategory_id : null,
                    'name'           => trim( (string) $request->name ),
                    'bundle_price'   => round( (float) $request->bundle_price, 2 ),
                    'status'         => $request->input( 'status', $bundle->status ) === 'inactive' ? 'inactive' : 'active',
                ] );

                ProductBundleItem::where( 'bundle_id', $bundle->id )->delete();
                $this->syncItems( $bundle, $request->input( 'items', [] ) );

                return $bundle->fresh( [
                    'category:id,name',
                    'subcategory:id,name,category_id',
                    'items.product:id,name,image,sku,selling_price,discount_price',
                ] );
            } );

            return response()->json( [
                'status'  => 200,
                'message' => 'Bundle updated successfully.',
                'bundle'  => $this->transformBundle( $bundle ),
            ] );
        } catch ( Throwable $e ) {
            return response()->json( [
                'status'  => 400,
                'message' => $e->getMessage(),
            ], 400 );
        }
    }

    public function destroy( $id )
    {
        if ( $deny = $this->denyUnlessMerchant() ) {
            return $deny;
        }

        $bundle = ProductBundle::find( $id );
        if ( ! $bundle ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Bundle not found',
            ], 404 );
        }

        DB::transaction( function () use ( $bundle ) {
            ProductBundleItem::where( 'bundle_id', $bundle->id )->delete();
            $bundle->delete();
        } );

        return response()->json( [
            'status'  => 200,
            'message' => 'Bundle deleted successfully.',
        ] );
    }

    public function status( $id )
    {
        if ( $deny = $this->denyUnlessMerchant() ) {
            return $deny;
        }

        $bundle = ProductBundle::find( $id );
        if ( ! $bundle ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'Bundle not found',
            ], 404 );
        }

        $bundle->status = $bundle->status === 'active' ? 'inactive' : 'active';
        $bundle->save();

        return response()->json( [
            'status'  => 200,
            'message' => $bundle->status === 'active' ? 'Bundle activated.' : 'Bundle deactivated.',
            'bundle'  => [
                'id'     => $bundle->id,
                'status' => $bundle->status,
            ],
        ] );
    }

    private function validator( Request $request )
    {
        return Validator::make( $request->all(), [
            'name'              => ['required', 'string', 'max:255'],
            'category_id'       => ['required', 'integer', 'min:1', new CategoryRule],
            'subcategory_id'    => ['nullable', 'integer', new SubCategorydRule],
            'bundle_price'      => ['required', 'numeric', 'min:0'],
            'status'            => ['nullable', 'in:active,inactive'],
            'items'             => ['required', 'array', 'min:2'],
            'items.*.product_id'=> ['required', 'integer', 'min:1'],
            'items.*.quantity'  => ['required', 'integer', 'min:1'],
        ], [
            'items.min' => 'A bundle must contain at least 2 products.',
        ] );
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems( ProductBundle $bundle, array $items ): void
    {
        $vendorId = vendorId();
        $seen     = [];

        foreach ( $items as $row ) {
            $productId = (int) ( $row['product_id'] ?? 0 );
            $qty       = max( 1, (int) ( $row['quantity'] ?? 1 ) );

            if ( $productId < 1 || isset( $seen[$productId] ) ) {
                continue;
            }

            $product = Product::where( 'id', $productId )
                ->where( 'vendor_id', $vendorId )
                ->where( 'status', 'active' )
                ->first();

            if ( ! $product ) {
                throw new \RuntimeException( 'Invalid product in bundle: #' . $productId );
            }

            ProductBundleItem::create( [
                'bundle_id'  => $bundle->id,
                'product_id' => $productId,
                'quantity'   => $qty,
            ] );

            $seen[$productId] = true;
        }

        if ( count( $seen ) < 2 ) {
            throw new \RuntimeException( 'A bundle must contain at least 2 distinct products.' );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function transformBundle( ProductBundle $bundle ): array
    {
        $items = $bundle->items->map( function ( ProductBundleItem $item ) {
            $product = $item->product;
            $unit    = $product
                ? (float) ( $product->discount_price ?: $product->selling_price ?: 0 )
                : 0.0;

            return [
                'id'           => $item->id,
                'product_id'   => (int) $item->product_id,
                'quantity'     => (int) $item->quantity,
                'unit_price'   => $unit,
                'line_total'   => round( $unit * (int) $item->quantity, 2 ),
                'product'      => $product ? [
                    'id'    => $product->id,
                    'name'  => $product->name,
                    'image' => $product->image,
                    'sku'   => $product->sku,
                ] : null,
            ];
        } )->values();

        $regular = round( (float) $items->sum( 'line_total' ), 2 );

        return [
            'id'              => $bundle->id,
            'name'            => $bundle->name,
            'category_id'     => $bundle->category_id,
            'subcategory_id'  => $bundle->subcategory_id,
            'category'        => $bundle->category,
            'subcategory'     => $bundle->subcategory,
            'bundle_price'    => (float) $bundle->bundle_price,
            'regular_price'   => $regular,
            'savings'         => round( max( 0, $regular - (float) $bundle->bundle_price ), 2 ),
            'status'          => $bundle->status,
            'items'           => $items,
            'created_at'      => optional( $bundle->created_at )->toDateTimeString(),
            'updated_at'      => optional( $bundle->updated_at )->toDateTimeString(),
        ];
    }

    private function denyUnlessMerchant()
    {
        if ( ! function_exists( 'tenant' ) || ! tenant() || tenant( 'type' ) !== 'merchant' ) {
            return response()->json( [
                'status'  => 403,
                'message' => 'Product bundles are only available for merchant tenants.',
            ], 403 );
        }

        return null;
    }
}
