<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Barcode;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

class BarcodeController extends Controller {
    // public function generate(Request $request)
    // {
    //     $variant_ids = $request->variant_id;
    //     $barcodes = [];
    //     $variants =[];

    //     foreach ($variant_ids as $key => $variant_id) {
    //         $productVariants = ProductVariant::find($variant_id);

    //         if (!$productVariants) {
    //             return response()->json([
    //                 'status' => 402,
    //                 'message' => 'Product Not found!',
    //             ]);
    //         }

    //         $maxQty = intval($productVariants->qty);
    //         $qty = $request->bar_qty[$key];

    //         if ($maxQty < $qty) {
    //             return response()->json([
    //                 'status' => 402,
    //                 'message' => 'Barcode quantity over your stock!',
    //             ]);
    //         }

    //         // Check if the variant_id already exists in the barcodes table
    //         $existingBarcode = Barcode::where('variant_id', $variant_id)->first();
    //         if ($existingBarcode) {
    //             // Skip storing barcode if variant_id already exists
    //             continue;
    //         }

    //         $bar = new Barcode();
    //         $bar->variant_id = $variant_id;
    //         $bar->barcode = rand(1111111111, 9999999999);
    //         $bar->vendor_id = vendorId();
    //         $bar->save();

    //         for ($i = 1; $i <= $qty; $i++) {
    //             // Store barcode in the array
    //             $barcodes[] = $bar->with('productVariant');
    //         }

    //     }

    //     return response()->json([
    //         'barcodes' => $barcodes,
    //     ]);

    // }

    public function generate( Request $request ) {
        $variant_ids = $request->variant_id;
        $barcodes    = [];

        foreach ( $variant_ids as $key => $variant_id ) {
            $productVariant = ProductVariant::find( $variant_id );

            if ( !$productVariant ) {
                return response()->json( [
                    'status'  => 402,
                    'message' => 'Product Not found!',
                ] );
            }

            $maxQty = intval( $productVariant->qty );
            $qty    = $request->bar_qty[$key];

            if ( $maxQty < $qty ) {
                return response()->json( [
                    'status'  => 402,
                    'message' => 'Barcode quantity over your stock!',
                ] );
            }

            // Check if the variant_id already exists in the barcodes table
            $existingBarcode = Barcode::where( 'variant_id', $variant_id )->first();
            if ( !$existingBarcode ) {
                $bar             = new Barcode();
                $bar->variant_id = $variant_id;
                $bar->barcode    = rand( 1111111111, 9999999999 );
                $bar->vendor_id  = vendorId();
                $bar->save();

                $existingBarcode = $bar;
            }

            for ( $i = 1; $i <= $qty; $i++ ) {
                // Store barcode and associated product variant in the array
                $barcodes[] = [
                    'barcode'         => $existingBarcode->barcode,
                    'product_variant' => $existingBarcode->productVariant,
                ];
            }
        }

        return response()->json( [
            'barcodes' => $barcodes,
        ] );
    }

    public function reGenerate( Request $request ) {

        $variant_ids = $request->variant_id;
        $barcodes    = [];

        foreach ( $variant_ids as $key => $variant ) {
            $barcode = Barcode::where( 'variant_id', $variant )
                ->with( 'productVariant', function ( $q ) {
                    $q->select( 'id', 'product_id', 'unit_id', 'size_id', 'color_id', 'qty' )->with( 'product', 'color', 'size', 'unit' );
                } )
                ->first();
            $qty = $request->bar_qty[$key];

            for ( $i = 1; $i <= $qty; $i++ ) {
                $barcodes[] = $barcode;
            }
        }

        return response()->json( ['barcodes' => $barcodes] );
    }

    public function manage() {
        $barcodes = Barcode::query()
            ->when( request( 'search' ), function ( $q ) {
                $searchTerm = request( 'search' );
                $q->where( function ( $query ) use ( $searchTerm ) {
                    $query->whereHas( 'productVariant', function ( $q ) use ( $searchTerm ) {
                        $q->whereHas( 'product', function ( $q ) use ( $searchTerm ) {
                            $q->where( 'name', 'like', "%$searchTerm%" );
                        } );
                    } )->orWhere( 'barcode', 'like', "%$searchTerm%" );
                } );
            } )
            ->with( 'productVariant', function ( $q ) {
                $q->select( 'id', 'product_id', 'unit_id', 'size_id', 'color_id', 'qty' )->with( 'product', 'color', 'size', 'unit' );
            } )->where( 'vendor_id', vendorId() )->get();

        return response()->json( [
            'status'   => 200,
            'barcodes' => $barcodes,

        ] );
    }
}
