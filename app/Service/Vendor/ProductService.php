<?php

namespace App\Service\Vendor;

use App\Models\Product;
use App\Models\ProductVariant;
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

            ->when( $startDate && $endDate, function ( $q ) use ( $startDate, $endDate ) {
                $q->whereDate( 'created_at', '>=', $startDate )
                    ->whereDate( 'created_at', '<=', $endDate );
            } )

            ->when( $min && $max, function ( $q ) use ( $min, $max ) {
                $q->whereBetween( 'selling_price', [$min, $max] );
            } )

            ->select( 'id', 'uniqid', 'image', 'name', 'selling_price', 'qty', 'status', 'created_at', 'discount_type', 'discount_rate', 'original_price', 'discount_price', 'is_affiliate', 'wc_product_id', 'product_type' )
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
        $variantsData = [];
        foreach ( $product_ids as $key => $product_id ) {
            $variantsData[] = [
                'user_id'    => vendorId(),
                'product_id' => $product_id,
                'unit_id'    => $variant['unit_id'][$key],
                'size_id'    => $variant['size_id'][$key],
                'color_id'   => $variant['color_id'][$key],
                'qty'        => $purchaseStatus == "received" ? $variant['qty'][$key] : 0,
                'rate'       => $variant['rate'][$key],
            ];
        }

        foreach ( $variantsData as $variantData ) {
            ProductVariant::updateOrCreate(
                [
                    'user_id'    => vendorId(),
                    'product_id' => $variantData['product_id'],
                    'unit_id'    => $variantData['unit_id'],
                    'size_id'    => $variantData['size_id'],
                    'color_id'   => $variantData['color_id'],
                ],
                [
                    'qty' => DB::raw( 'qty + ' . $variantData['qty'] ), // Increment the qty column
                    // 'rate' => $variantData['rate'], // Update rate if needed
                ]
            );

            // Update qty for the product
            $product = Product::find( $variantData['product_id'] );
            if ( $product && $purchaseStatus == "received" ) {
                $product->qty += $variantData['qty']; // Increase stock
                $product->save();
            }
        }

    }

}
