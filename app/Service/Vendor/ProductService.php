<?php

namespace App\Service\Vendor;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Service\Vendor\ProductVariantService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ProductService {

    public static function index() {
        $userId    = Auth::id();
        $startDate = request( 'start_date' );
        $endDate   = request( 'end_date' );
        $min       = request( 'min_price' );
        $max       = request( 'max_price' );

        $product = Product::where( 'vendor_id', vendorId() )
            ->when( request( 'status' ) == 'pending', function ( $q ) {
                return $q->where( 'status', 'pending' );
            } )
            ->when( request( 'status' ) == 'rejected', function ( $q ) {
                return $q->where( 'status', 'rejected' );
            } )
        // ->when( request( 'search' ), fn( $q, $search ) => $q->search( $search ) )
            ->when( request( 'search' ), function ( $q, $search ) {
                return $q->where( 'name', 'LIKE', '%' . $search . "%" )
                    ->orWhere( 'sku', 'LIKE', '%' . $search . "%" )
                    ->orWhere( 'qty', 'LIKE', '%' . $search . "%" )
                    ->orWhere( 'uniqid', 'LIKE', '%' . $search . "%" );
            } )

            ->when( request( 'status' ) == 'active', function ( $q ) {
                return $q->where( 'status', 'active' );
            } )

            ->when( request( 'category' ), function ( $q ) {
                return $q->where( 'category_id', request( 'category' ) );
            } )

            ->when( request()->filled( 'is_affiliate' ), function ( $q ) {
                $isAffiliate = request( 'is_affiliate' );
                // Accept 1/0, "1"/"0", true/false, "true"/"false"
                if ( in_array( $isAffiliate, [1, '1', true, 'true'], true ) ) {
                    return $q->where( 'is_affiliate', 1 );
                }
                if ( in_array( $isAffiliate, [0, '0', false, 'false'], true ) ) {
                    return $q->where( function ( $query ) {
                        $query->where( 'is_affiliate', 0 )->orWhereNull( 'is_affiliate' );
                    } );
                }

                return $q->where( 'is_affiliate', (int) $isAffiliate );
            } )

            ->when( $startDate && $endDate, function ( $q ) use ( $startDate, $endDate ) {
                $q->whereDate( 'created_at', '>=', $startDate )
                    ->whereDate( 'created_at', '<=', $endDate );
            } )

            ->when( $min && $max, function ( $q ) use ( $min, $max ) {
                $q->whereBetween( 'selling_price', [$min, $max] );
            } )

            ->select( 'id', 'uniqid', 'image', 'name', 'selling_price', 'qty', 'status', 'created_at', 'discount_type', 'discount_rate', 'original_price', 'discount_price', 'is_affiliate', 'wc_product_id', 'product_type', 'is_stock_show', 'is_show_website', 'slug' )
            ->latest()
            ->paginate( 10 )
            ->withQueryString();
        return $product;
    }

    // public static function showSLider()
    // {
    //     $sliders = Slider::all();
    //     return $sliders;
    // }

    public static function countThis() {
        $vendorId = vendorId();

        $counts = Product::select( 'status', DB::raw( 'COUNT(*) as total' ) )
            ->where( 'vendor_id', $vendorId )
            ->whereIn( 'status', ['active', 'pending', 'rejected'] )
            ->groupBy( 'status' )
            ->pluck( 'total', 'status' );

        return [
            'active'   => $counts['active'] ?? 0,
            'pending'  => $counts['pending'] ?? 0,
            'rejected' => $counts['rejected'] ?? 0,
            'total'    => array_sum( $counts->toArray() ),
        ];
    }

    public static function productVariants( $product_ids, $variant, $purchaseStatus ) {
        if ( $purchaseStatus !== 'received' ) {
            return;
        }

        foreach ( $product_ids as $key => $product_id ) {
            $qty = max( 0, (int) ( $variant['qty'][$key] ?? 0 ) );
            if ( $qty <= 0 ) {
                continue;
            }

            $variantId = ProductVariantService::normalizeNullableId( $variant['variant_id'][$key] ?? null );

            if ( $variantId ) {
                ProductVariantService::incrementStock(
                    (int) $product_id,
                    ProductVariantService::normalizeNullableId( $variant['unit_id'][$key] ?? null ),
                    ProductVariantService::normalizeNullableId( $variant['size_id'][$key] ?? null ),
                    ProductVariantService::normalizeNullableId( $variant['color_id'][$key] ?? null ),
                    $qty,
                    vendorId(),
                    $variantId
                );

                continue;
            }

            // Legacy unit/size/color lines (no variant_id).
            $unitId  = ProductVariantService::normalizeNullableId( $variant['unit_id'][$key] ?? null );
            $sizeId  = ProductVariantService::normalizeNullableId( $variant['size_id'][$key] ?? null );
            $colorId = ProductVariantService::normalizeNullableId( $variant['color_id'][$key] ?? null );

            if ( $unitId || $sizeId || $colorId ) {
                ProductVariantService::incrementStock(
                    (int) $product_id,
                    $unitId,
                    $sizeId,
                    $colorId,
                    $qty,
                    vendorId()
                );

                continue;
            }

            $product = \App\Models\Product::find( $product_id );
            if ( $product ) {
                $product->qty = (string) ( max( 0, (int) $product->qty ) + $qty );
                $product->saveQuietly();
            }
        }
    }

}
