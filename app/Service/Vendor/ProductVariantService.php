<?php

namespace App\Service\Vendor;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class ProductVariantService {

    public static function normalizeNullableId( mixed $value ): ?int {
        if ( $value === null || $value === '' ) {
            return null;
        }

        $intValue = (int) $value;

        return $intValue === 0 ? null : $intValue;
    }

    public static function resolveAttributeId( ?array $variant, string $key, ?string $flatKey = null ): ?int {
        if ( ! is_array( $variant ) ) {
            return null;
        }

        $value = $variant[$key] ?? ( $flatKey ? ( $variant[$flatKey] ?? null ) : null );

        if ( is_array( $value ) ) {
            return self::normalizeNullableId( $value['id'] ?? null );
        }

        return self::normalizeNullableId( $value );
    }

    public static function resolveSizeId( ?array $variant ): ?int {
        if ( ! is_array( $variant ) ) {
            return null;
        }

        return self::resolveAttributeId( $variant, 'size', 'size_id' )
            ?? self::resolveAttributeId( $variant, 'variation', 'variation_id' );
    }

    /**
     * @return array{product_id: int, unit_id: ?int, size_id: ?int, color_id: ?int}
     */
    public static function variantKey( int $productId, ?int $unitId, ?int $sizeId, ?int $colorId ): array {
        return [
            'product_id' => $productId,
            'unit_id'    => self::normalizeNullableId( $unitId ),
            'size_id'    => self::normalizeNullableId( $sizeId ),
            'color_id'   => self::normalizeNullableId( $colorId ),
        ];
    }

    public static function findVariant( int $productId, ?int $unitId, ?int $sizeId, ?int $colorId ): ?ProductVariant {
        self::mergeDuplicateVariants( $productId );

        return ProductVariant::where( self::variantKey( $productId, $unitId, $sizeId, $colorId ) )->first();
    }

    public static function findVariantById( int $productId, int $variantId ): ?ProductVariant {
        return ProductVariant::where( 'product_id', $productId )
            ->where( 'id', $variantId )
            ->first();
    }

    public static function findOrCreateVariant(
        int $productId,
        ?int $unitId,
        ?int $sizeId,
        ?int $colorId,
        ?int $vendorId = null,
        ?int $initialQty = null
    ): ProductVariant {
        $variant = self::findVariant( $productId, $unitId, $sizeId, $colorId );

        if ( $variant ) {
            if ( ! $variant->user_id && $vendorId ) {
                $variant->user_id = $vendorId;
                $variant->save();
            }

            return $variant;
        }

        $qty = $initialQty ?? 0;

        if ( $qty === 0 ) {
            $variantCount = ProductVariant::where( 'product_id', $productId )->count();
            $product      = Product::find( $productId );

            if ( $variantCount === 0 && $product ) {
                $qty = max( 0, (int) $product->qty );
            }
        }

        return ProductVariant::create( [
            'user_id'    => $vendorId ?? vendorId(),
            'product_id' => $productId,
            'unit_id'    => self::normalizeNullableId( $unitId ),
            'size_id'    => self::normalizeNullableId( $sizeId ),
            'color_id'   => self::normalizeNullableId( $colorId ),
            'qty'        => $qty,
            'rate'       => 0,
        ] );
    }

    public static function decrementStockById( int $variantId, int $qty ): void {
        if ( $qty <= 0 ) {
            return;
        }

        $variant = ProductVariant::find( $variantId );

        if ( ! $variant ) {
            return;
        }

        $variant->qty = max( 0, (int) $variant->qty - $qty );
        $variant->save();

        $product = Product::find( $variant->product_id );

        if ( $product ) {
            self::syncJsonFromDb( $product );
            self::recalculateProductQty( $product );
        }
    }

    public static function incrementStockById( int $variantId, int $qty ): void {
        if ( $qty <= 0 ) {
            return;
        }

        $variant = ProductVariant::find( $variantId );

        if ( ! $variant ) {
            return;
        }

        $variant->qty = (int) $variant->qty + $qty;
        $variant->save();

        $product = Product::find( $variant->product_id );

        if ( $product ) {
            self::syncJsonFromDb( $product );
            self::recalculateProductQty( $product );
        }
    }

    public static function decrementStock(
        int $productId,
        ?int $unitId,
        ?int $sizeId,
        ?int $colorId,
        int $qty,
        ?int $vendorId = null,
        ?int $variantId = null
    ): void {
        if ( $qty <= 0 ) {
            return;
        }

        DB::transaction( function () use ( $productId, $unitId, $sizeId, $colorId, $qty, $vendorId, $variantId ) {
            if ( $variantId ) {
                $variant = self::findVariantById( $productId, $variantId );

                if ( $variant ) {
                    $variant->qty = max( 0, (int) $variant->qty - $qty );
                    $variant->save();

                    $product = Product::find( $productId );

                    if ( $product ) {
                        self::syncJsonFromDb( $product );
                        self::recalculateProductQty( $product );
                    }

                    return;
                }
            }

            $variant = self::findOrCreateVariant( $productId, $unitId, $sizeId, $colorId, $vendorId );
            $variant->qty = max( 0, (int) $variant->qty - $qty );
            $variant->save();

            $product = Product::find( $productId );

            if ( $product ) {
                self::syncJsonFromDb( $product );
                self::recalculateProductQty( $product );
            }
        } );
    }

    public static function incrementStock(
        int $productId,
        ?int $unitId,
        ?int $sizeId,
        ?int $colorId,
        int $qty,
        ?int $vendorId = null,
        ?int $variantId = null
    ): void {
        if ( $qty <= 0 ) {
            return;
        }

        DB::transaction( function () use ( $productId, $unitId, $sizeId, $colorId, $qty, $vendorId, $variantId ) {
            if ( $variantId ) {
                $variant = self::findVariantById( $productId, $variantId );

                if ( $variant ) {
                    $variant->qty = (int) $variant->qty + $qty;
                    $variant->save();

                    $product = Product::find( $productId );

                    if ( $product ) {
                        self::syncJsonFromDb( $product );
                        self::recalculateProductQty( $product );
                    }

                    return;
                }
            }

            $variant = self::findOrCreateVariant( $productId, $unitId, $sizeId, $colorId, $vendorId );
            $variant->qty = (int) $variant->qty + $qty;
            $variant->save();

            $product = Product::find( $productId );

            if ( $product ) {
                self::syncJsonFromDb( $product );
                self::recalculateProductQty( $product );
            }
        } );
    }

    public static function syncFromProductVariantsJson( Product $product, ?int $vendorId = null, bool $preserveExistingStock = false ): void {
        $variants = $product->variants;

        if ( ! is_array( $variants ) || $variants === [] ) {
            return;
        }

        $vendorId     = $vendorId ?? $product->vendor_id ?? vendorId();
        $syncedJson   = [];
        $processedIds = [];

        DB::transaction( function () use ( $product, $variants, $vendorId, $preserveExistingStock, &$syncedJson, &$processedIds ) {
            foreach ( $variants as $variant ) {
                $variant = (array) $variant;
                $unitId  = self::resolveAttributeId( $variant, 'unit', 'unit_id' );
                $sizeId  = self::resolveSizeId( $variant );
                $colorId = self::resolveAttributeId( $variant, 'color', 'color_id' );
                $qty     = max( 0, (int) ( $variant['qty'] ?? 0 ) );

                $existing = null;

                if ( ! empty( $variant['variant_id'] ) || ! empty( $variant['id'] ) ) {
                    $existingId = (int) ( $variant['variant_id'] ?? $variant['id'] );
                    $existing   = ProductVariant::where( 'product_id', $product->id )
                        ->where( 'id', $existingId )
                        ->first();
                }

                if ( ! $existing ) {
                    $existing = self::findVariant( $product->id, $unitId, $sizeId, $colorId );
                }

                if ( $existing ) {
                    $existing->user_id  = $existing->user_id ?? $vendorId;
                    $existing->unit_id  = $unitId;
                    $existing->size_id  = $sizeId;
                    $existing->color_id = $colorId;

                    if ( ! $preserveExistingStock ) {
                        $existing->qty = $qty;
                    }

                    $existing->save();
                } else {
                    $existing = ProductVariant::create( [
                        'user_id'    => $vendorId,
                        'product_id' => $product->id,
                        'unit_id'    => $unitId,
                        'size_id'    => $sizeId,
                        'color_id'   => $colorId,
                        'qty'        => $qty,
                        'rate'       => (float) ( $variant['rate'] ?? 0 ),
                    ] );
                }

                $processedIds[]        = $existing->id;
                $variant['id']         = $existing->id;
                $variant['variant_id'] = $existing->id;
                $variant['qty']        = max( 0, (int) $existing->qty );
                $variant['unit_id']    = $existing->unit_id;
                $variant['size_id']    = $existing->size_id;
                $variant['color_id']   = $existing->color_id;
                $syncedJson[]          = $variant;
            }

            self::mergeDuplicateVariants( $product->id, $processedIds );
            $product->variants = $syncedJson;
            self::recalculateProductQty( $product );
        } );
    }

    public static function reconcileProduct( Product $product ): void {
        DB::transaction( function () use ( $product ) {
            $variantCount = ProductVariant::where( 'product_id', $product->id )->count();

            if ( $variantCount === 0 && is_array( $product->variants ) && $product->variants !== [] ) {
                self::syncFromProductVariantsJson( $product, null, false );

                return;
            }

            self::mergeDuplicateVariants( $product->id );
            self::syncJsonFromDb( $product );
            self::recalculateProductQty( $product );
        } );
    }

    public static function recalculateProductQty( Product $product ): void {
        if ( ! ProductVariant::where( 'product_id', $product->id )->exists() ) {
            return;
        }

        $totalQty     = (int) ProductVariant::where( 'product_id', $product->id )->sum( 'qty' );
        $product->qty = (string) max( 0, $totalQty );
        $product->saveQuietly();
    }

    public static function syncJsonFromDb( Product $product ): void {
        $dbVariants = ProductVariant::where( 'product_id', $product->id )
            ->get( ['id', 'unit_id', 'size_id', 'color_id', 'qty'] );

        if ( $dbVariants->isEmpty() ) {
            return;
        }

        $jsonVariants = is_array( $product->variants ) ? $product->variants : [];
        $updatedJson  = [];
        $usedDbIds    = [];

        foreach ( $dbVariants as $dbVariant ) {
            $matched = false;

            foreach ( $jsonVariants as $jsonVariant ) {
                $jsonVariant = (array) $jsonVariant;

                if ( self::variantAttributesMatch( $jsonVariant, $dbVariant ) ) {
                    $jsonVariant['id']         = $dbVariant->id;
                    $jsonVariant['variant_id'] = $dbVariant->id;
                    $jsonVariant['qty']        = max( 0, (int) $dbVariant->qty );
                    $jsonVariant['unit_id']    = $dbVariant->unit_id;
                    $jsonVariant['size_id']    = $dbVariant->size_id;
                    $jsonVariant['color_id']   = $dbVariant->color_id;
                    $updatedJson[]             = $jsonVariant;
                    $usedDbIds[]               = $dbVariant->id;
                    $matched                   = true;
                    break;
                }
            }

            if ( ! $matched ) {
                $updatedJson[] = [
                    'id'         => $dbVariant->id,
                    'variant_id' => $dbVariant->id,
                    'unit_id'    => $dbVariant->unit_id,
                    'size_id'    => $dbVariant->size_id,
                    'color_id'   => $dbVariant->color_id,
                    'qty'        => max( 0, (int) $dbVariant->qty ),
                ];
                $usedDbIds[] = $dbVariant->id;
            }
        }

        $product->variants = $updatedJson;
        $product->saveQuietly();
    }

    private static function variantAttributesMatch( array $jsonVariant, ProductVariant $dbVariant ): bool {
        return self::normalizeNullableId( self::resolveAttributeId( $jsonVariant, 'unit', 'unit_id' ) )
                === self::normalizeNullableId( $dbVariant->unit_id )
            && self::normalizeNullableId( self::resolveSizeId( $jsonVariant ) )
                === self::normalizeNullableId( $dbVariant->size_id )
            && self::normalizeNullableId( self::resolveAttributeId( $jsonVariant, 'color', 'color_id' ) )
                === self::normalizeNullableId( $dbVariant->color_id );
    }

    /**
     * @param  array<int>  $preferredIds
     */
    private static function mergeDuplicateVariants( int $productId, array $preferredIds = [] ): void {
        $variants = ProductVariant::where( 'product_id', $productId )->get();

        $groups = $variants->groupBy( fn ( $variant ) => implode( '-', [
            self::normalizeNullableId( $variant->unit_id ) ?? 'null',
            self::normalizeNullableId( $variant->size_id ) ?? 'null',
            self::normalizeNullableId( $variant->color_id ) ?? 'null',
        ] ) );

        foreach ( $groups as $group ) {
            if ( $group->count() <= 1 ) {
                $variant      = $group->first();
                $variant->qty = max( 0, (int) $variant->qty );
                $variant->save();

                continue;
            }

            $keep = $group->first( fn ( $variant ) => in_array( $variant->id, $preferredIds, true ) )
                ?? $group->sortByDesc( 'qty' )->first();

            $keep->qty     = max( 0, (int) $group->sum( 'qty' ) );
            $keep->user_id = $keep->user_id ?? vendorId();
            $keep->save();

            $group->where( 'id', '!=', $keep->id )->each->delete();
        }
    }

}
