<?php

namespace App\Service\Vendor;

use App\Models\Product;
use App\Models\ProductVariant;

class ProductVariantService {

    public static function resolveAttributeId( ?array $variant, string $key, ?string $flatKey = null ): ?int {
        if ( ! is_array( $variant ) ) {
            return null;
        }

        $value = $variant[$key] ?? ( $flatKey ? ( $variant[$flatKey] ?? null ) : null );

        if ( is_array( $value ) ) {
            return isset( $value['id'] ) ? (int) $value['id'] : null;
        }

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    /**
     * @return array{product_id: int, unit_id: ?int, size_id: ?int, color_id: ?int}
     */
    public static function variantKey( int $productId, ?int $unitId, ?int $sizeId, ?int $colorId ): array {
        return [
            'product_id' => $productId,
            'unit_id'    => $unitId,
            'size_id'    => $sizeId,
            'color_id'   => $colorId,
        ];
    }

    public static function findVariant( int $productId, ?int $unitId, ?int $sizeId, ?int $colorId ): ?ProductVariant {
        return ProductVariant::where( self::variantKey( $productId, $unitId, $sizeId, $colorId ) )->first();
    }

    public static function findOrCreateVariant(
        int $productId,
        ?int $unitId,
        ?int $sizeId,
        ?int $colorId,
        ?int $vendorId = null
    ): ProductVariant {
        $variant = self::findVariant( $productId, $unitId, $sizeId, $colorId );

        if ( $variant ) {
            if ( ! $variant->user_id && $vendorId ) {
                $variant->user_id = $vendorId;
                $variant->save();
            }

            return $variant;
        }

        return ProductVariant::create( [
            'user_id'    => $vendorId ?? vendorId(),
            'product_id' => $productId,
            'unit_id'    => $unitId,
            'size_id'    => $sizeId,
            'color_id'   => $colorId,
            'qty'        => 0,
            'rate'       => 0,
        ] );
    }

    public static function decrementStock(
        int $productId,
        ?int $unitId,
        ?int $sizeId,
        ?int $colorId,
        int $qty,
        ?int $vendorId = null
    ): void {
        if ( $qty <= 0 ) {
            return;
        }

        $variant = self::findOrCreateVariant( $productId, $unitId, $sizeId, $colorId, $vendorId );
        $variant->qty = max( 0, (int) $variant->qty - $qty );
        $variant->save();

        $product = Product::find( $productId );

        if ( $product ) {
            self::syncJsonFromDb( $product );
            self::recalculateProductQty( $product );
        }
    }

    public static function incrementStock(
        int $productId,
        ?int $unitId,
        ?int $sizeId,
        ?int $colorId,
        int $qty,
        ?int $vendorId = null
    ): void {
        if ( $qty <= 0 ) {
            return;
        }

        $variant = self::findOrCreateVariant( $productId, $unitId, $sizeId, $colorId, $vendorId );
        $variant->qty = (int) $variant->qty + $qty;
        $variant->save();

        $product = Product::find( $productId );

        if ( $product ) {
            self::syncJsonFromDb( $product );
            self::recalculateProductQty( $product );
        }
    }

    public static function syncFromProductVariantsJson( Product $product, ?int $vendorId = null ): void {
        $variants = $product->variants;

        if ( ! is_array( $variants ) || $variants === [] ) {
            return;
        }

        $vendorId   = $vendorId ?? $product->vendor_id ?? vendorId();
        $syncedJson = [];
        $processedIds = [];

        foreach ( $variants as $variant ) {
            $variant = (array) $variant;
            $unitId  = self::resolveAttributeId( $variant, 'unit', 'unit_id' );
            $sizeId  = self::resolveAttributeId( $variant, 'size', 'size_id' );
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
                $existing->qty      = $qty;
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

            $processedIds[]           = $existing->id;
            $variant['id']            = $existing->id;
            $variant['variant_id']    = $existing->id;
            $syncedJson[]             = $variant;
        }

        self::mergeDuplicateVariants( $product->id, $processedIds );
        $product->variants = $syncedJson;
        self::recalculateProductQty( $product );
    }

    public static function reconcileProduct( Product $product ): void {
        $variantCount = ProductVariant::where( 'product_id', $product->id )->count();

        if ( $variantCount === 0 && is_array( $product->variants ) && $product->variants !== [] ) {
            self::syncFromProductVariantsJson( $product );

            return;
        }

        self::mergeDuplicateVariants( $product->id );
        self::syncJsonFromDb( $product );
        self::recalculateProductQty( $product );
    }

    public static function recalculateProductQty( Product $product ): void {
        $totalQty = (int) ProductVariant::where( 'product_id', $product->id )->sum( 'qty' );

        if ( $totalQty > 0 || ProductVariant::where( 'product_id', $product->id )->exists() ) {
            $product->qty = (string) $totalQty;
            $product->saveQuietly();
        }
    }

    public static function syncJsonFromDb( Product $product ): void {
        $dbVariants = ProductVariant::where( 'product_id', $product->id )
            ->get( ['id', 'unit_id', 'size_id', 'color_id', 'qty'] );

        if ( $dbVariants->isEmpty() ) {
            return;
        }

        $jsonVariants = is_array( $product->variants ) ? $product->variants : [];
        $updatedJson  = [];

        foreach ( $dbVariants as $dbVariant ) {
            $matched = false;

            foreach ( $jsonVariants as $jsonVariant ) {
                $jsonVariant = (array) $jsonVariant;

                if (
                    self::resolveAttributeId( $jsonVariant, 'unit', 'unit_id' ) === (int) $dbVariant->unit_id
                    && self::resolveAttributeId( $jsonVariant, 'size', 'size_id' ) === (int) $dbVariant->size_id
                    && self::resolveAttributeId( $jsonVariant, 'color', 'color_id' ) === (int) $dbVariant->color_id
                ) {
                    $jsonVariant['id']         = $dbVariant->id;
                    $jsonVariant['variant_id']   = $dbVariant->id;
                    $jsonVariant['qty']          = max( 0, (int) $dbVariant->qty );
                    $jsonVariant['unit_id']      = $dbVariant->unit_id;
                    $jsonVariant['size_id']      = $dbVariant->size_id;
                    $jsonVariant['color_id']     = $dbVariant->color_id;
                    $updatedJson[]               = $jsonVariant;
                    $matched                     = true;
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
            }
        }

        $product->variants = $updatedJson;
        $product->saveQuietly();
    }

    /**
     * @param  array<int>  $preferredIds
     */
    private static function mergeDuplicateVariants( int $productId, array $preferredIds = [] ): void {
        $variants = ProductVariant::where( 'product_id', $productId )->get();

        $groups = $variants->groupBy( fn ( $variant ) => implode( '-', [
            $variant->unit_id ?? 'null',
            $variant->size_id ?? 'null',
            $variant->color_id ?? 'null',
        ] ) );

        foreach ( $groups as $group ) {
            if ( $group->count() <= 1 ) {
                $variant = $group->first();
                $variant->qty = max( 0, (int) $variant->qty );
                $variant->save();

                continue;
            }

            $keep = $group->first( fn ( $variant ) => in_array( $variant->id, $preferredIds, true ) )
                ?? $group->sortByDesc( 'qty' )->first();

            $keep->qty      = max( 0, (int) $group->sum( 'qty' ) );
            $keep->user_id  = $keep->user_id ?? vendorId();
            $keep->save();

            $group->where( 'id', '!=', $keep->id )->each->delete();
        }
    }

}
