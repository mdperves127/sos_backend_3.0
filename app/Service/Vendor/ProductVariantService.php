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

    /**
     * Product-level sell price used when a variant has no separate price (rate <= 0).
     */
    public static function productFallbackPrice( object $product ): float {
        if ( isset( $product->discount_price ) && $product->discount_price !== null && $product->discount_price !== '' ) {
            return (float) $product->discount_price;
        }

        return (float) ( $product->selling_price ?? 0 );
    }

    /**
     * Accept `price` or `rate` from tenant panel payloads. Non-negative; 0 means "use product price".
     */
    public static function resolveRateFromPayload( array $variant ): float {
        if ( array_key_exists( 'price', $variant ) && $variant['price'] !== null && $variant['price'] !== '' ) {
            return max( 0, (float) $variant['price'] );
        }

        if ( array_key_exists( 'rate', $variant ) && $variant['rate'] !== null && $variant['rate'] !== '' ) {
            return max( 0, (float) $variant['rate'] );
        }

        return 0.0;
    }

    /**
     * Whether the payload explicitly includes a variant unit price.
     */
    public static function payloadHasRate( array $variant ): bool {
        return ( array_key_exists( 'price', $variant ) && $variant['price'] !== null && $variant['price'] !== '' )
            || ( array_key_exists( 'rate', $variant ) && $variant['rate'] !== null && $variant['rate'] !== '' );
    }

    /**
     * Stable fingerprint from option attribute/value ids (order-independent).
     */
    public static function optionsFingerprint( array $variant ): string {
        $options = $variant['options'] ?? [];
        if ( is_string( $options ) ) {
            $decoded = json_decode( $options, true );
            $options = is_array( $decoded ) ? $decoded : [];
        }

        if ( ! is_array( $options ) || $options === [] ) {
            return '';
        }

        $pairs = [];
        foreach ( $options as $option ) {
            $option = (array) $option;
            $attributeId = (string) ( $option['attribute_id'] ?? '' );
            $valueId     = (string) ( $option['value_id'] ?? '' );
            if ( $attributeId === '' && $valueId === '' ) {
                continue;
            }
            $pairs[] = $attributeId . ':' . $valueId;
        }

        sort( $pairs );

        return implode( '|', $pairs );
    }

    /**
     * Fill missing variant ids from a previous products.variants JSON snapshot
     * using key, sku, or options fingerprint. Needed because the price tab often
     * sends empty id/variant_id for option-based combinations.
     *
     * @param  array<int, array<string, mixed>>  $incoming
     * @param  array<int, mixed>                 $existingJson
     * @return array<int, array<string, mixed>>
     */
    public static function rehydrateVariantIds( array $incoming, array $existingJson ): array {
        $byKey     = [];
        $bySku     = [];
        $byOptions = [];

        foreach ( $existingJson as $existing ) {
            $existing = (array) $existing;
            $id       = (int) ( $existing['variant_id'] ?? $existing['id'] ?? 0 );
            if ( $id <= 0 ) {
                continue;
            }

            $key = isset( $existing['key'] ) ? trim( (string) $existing['key'] ) : '';
            if ( $key !== '' ) {
                $byKey[$key] = $id;
            }

            $sku = isset( $existing['sku'] ) ? trim( (string) $existing['sku'] ) : '';
            if ( $sku !== '' ) {
                $bySku[$sku] = $id;
            }

            $fingerprint = self::optionsFingerprint( $existing );
            if ( $fingerprint !== '' ) {
                $byOptions[$fingerprint] = $id;
            }
        }

        foreach ( $incoming as $index => $variant ) {
            $variant = (array) $variant;
            $id      = (int) ( $variant['variant_id'] ?? $variant['id'] ?? 0 );
            if ( $id > 0 ) {
                $incoming[$index] = $variant;
                continue;
            }

            $resolved = null;
            $key      = isset( $variant['key'] ) ? trim( (string) $variant['key'] ) : '';
            if ( $key !== '' && isset( $byKey[$key] ) ) {
                $resolved = $byKey[$key];
            }

            if ( $resolved === null ) {
                $sku = isset( $variant['sku'] ) ? trim( (string) $variant['sku'] ) : '';
                if ( $sku !== '' && isset( $bySku[$sku] ) ) {
                    $resolved = $bySku[$sku];
                }
            }

            if ( $resolved === null ) {
                $fingerprint = self::optionsFingerprint( $variant );
                if ( $fingerprint !== '' && isset( $byOptions[$fingerprint] ) ) {
                    $resolved = $byOptions[$fingerprint];
                }
            }

            if ( $resolved !== null ) {
                $variant['id']         = $resolved;
                $variant['variant_id'] = $resolved;
            }

            $incoming[$index] = $variant;
        }

        return $incoming;
    }

    /**
     * Unit sell price for a selected variant, falling back to product price when rate is missing/zero.
     */
    public static function resolveVariantUnitPrice( ?object $variant, object $product ): float {
        $fallback = self::productFallbackPrice( $product );

        if ( ! $variant ) {
            return $fallback;
        }

        $rate = (float) ( $variant->rate ?? 0 );

        return $rate > 0 ? $rate : $fallback;
    }

    /**
     * Build cart unit/total pricing for single-purchase lines that may mix variants.
     *
     * @param  array<int, array<string, mixed>>|\Illuminate\Support\Collection  $cartItems
     * @return array{product_price: float, total_product_price: float, total_qty: int}
     */
    public static function lineTotalsFromCartItems( object $product, $cartItems, ?string $connectionName = null ): array {
        $items   = collect( $cartItems );
        $totalQty = 0;
        $totalAmount = 0.0;
        $fallback = self::productFallbackPrice( $product );

        if ( $items->isEmpty() ) {
            return [
                'product_price'       => $fallback,
                'total_product_price' => 0.0,
                'total_qty'           => 0,
            ];
        }

        $variantIds = $items
            ->map( function ( $item ) {
                $item = (array) $item;

                return (int) ( $item['variant_id'] ?? 0 );
            } )
            ->filter( fn ( $id ) => $id > 0 )
            ->unique()
            ->values()
            ->all();

        $ratesById = [];

        if ( $variantIds !== [] ) {
            if ( $connectionName ) {
                $ratesById = DB::connection( $connectionName )
                    ->table( 'product_variants' )
                    ->where( 'product_id', $product->id )
                    ->whereIn( 'id', $variantIds )
                    ->pluck( 'rate', 'id' )
                    ->map( fn ( $rate ) => (float) $rate )
                    ->all();
            } else {
                $ratesById = ProductVariant::query()
                    ->where( 'product_id', $product->id )
                    ->whereIn( 'id', $variantIds )
                    ->pluck( 'rate', 'id' )
                    ->map( fn ( $rate ) => (float) $rate )
                    ->all();
            }
        }

        foreach ( $items as $item ) {
            $item = (array) $item;
            $qty  = max( 0, (int) ( $item['qty'] ?? 0 ) );

            if ( $qty <= 0 ) {
                continue;
            }

            $variantId = (int) ( $item['variant_id'] ?? 0 );
            $rate      = $variantId > 0 ? (float) ( $ratesById[$variantId] ?? 0 ) : 0.0;
            $unitPrice = $rate > 0 ? $rate : $fallback;

            $totalQty    += $qty;
            $totalAmount += $unitPrice * $qty;
        }

        $unitPrice = $totalQty > 0 ? ( $totalAmount / $totalQty ) : $fallback;

        return [
            'product_price'       => round( $unitPrice, 2 ),
            'total_product_price' => round( $totalAmount, 2 ),
            'total_qty'           => $totalQty,
        ];
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
            $product = Product::find( $productId );

            if ( $variantId ) {
                $variant = self::findVariantById( $productId, $variantId );

                if ( $variant ) {
                    $variant->qty = max( 0, (int) $variant->qty - $qty );
                    $variant->save();

                    if ( $product ) {
                        $product->qty = (string) max( 0, (int) $product->qty - $qty );
                        $product->saveQuietly();
                        self::syncJsonFromDb( $product );
                    }

                    return;
                }
            }

            $variant = self::findOrCreateVariant( $productId, $unitId, $sizeId, $colorId, $vendorId );
            $variant->qty = max( 0, (int) $variant->qty - $qty );
            $variant->save();

            if ( $product ) {
                $product->qty = (string) max( 0, (int) $product->qty - $qty );
                $product->saveQuietly();
                self::syncJsonFromDb( $product );
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
        $connection   = $product->getConnectionName() ?: config( 'database.default' );

        DB::connection( $connection )->transaction( function () use ( $product, $variants, $vendorId, $preserveExistingStock, &$syncedJson, &$processedIds ) {
            foreach ( $variants as $variant ) {
                $variant = (array) $variant;
                $unitId  = self::resolveAttributeId( $variant, 'unit', 'unit_id' );
                $sizeId  = self::resolveSizeId( $variant );
                $colorId = self::resolveAttributeId( $variant, 'color', 'color_id' );
                $qty     = max( 0, (int) ( $variant['qty'] ?? 0 ) );
                $hasRate = self::payloadHasRate( $variant );
                $rate    = $hasRate ? self::resolveRateFromPayload( $variant ) : null;

                $existing = null;

                if ( ! empty( $variant['variant_id'] ) || ! empty( $variant['id'] ) ) {
                    $existingId = (int) ( $variant['variant_id'] ?? $variant['id'] );
                    // Ignore non-persisted / frontend temp ids.
                    if ( $existingId > 0 && ! in_array( $existingId, $processedIds, true ) ) {
                        $existing = ProductVariant::where( 'product_id', $product->id )
                            ->where( 'id', $existingId )
                            ->first();
                    }
                }

                // Only collapse by unit/size/color when at least one attr is set.
                // Option-based variants often have all-null attrs and must stay separate.
                if ( ! $existing && ( $unitId !== null || $sizeId !== null || $colorId !== null ) ) {
                    $candidate = self::findVariant( $product->id, $unitId, $sizeId, $colorId );
                    if ( $candidate && ! in_array( $candidate->id, $processedIds, true ) ) {
                        $existing = $candidate;
                    }
                }

                if ( $existing ) {
                    $existing->user_id  = $existing->user_id ?? $vendorId;
                    $existing->unit_id  = $unitId;
                    $existing->size_id  = $sizeId;
                    $existing->color_id = $colorId;

                    if ( $hasRate ) {
                        $existing->rate = $rate;
                    }

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
                        'rate'       => $rate ?? 0,
                    ] );
                }

                // Hard-guarantee rate persistence for priced payloads.
                if ( $hasRate ) {
                    ProductVariant::where( 'id', $existing->id )->update( [
                        'rate' => $rate,
                    ] );
                    $existing->rate = $rate;
                }

                $processedIds[]        = $existing->id;
                $variant['id']         = $existing->id;
                $variant['variant_id'] = $existing->id;
                $variant['qty']        = max( 0, (int) $existing->qty );
                $variant['rate']       = (float) ( $existing->rate ?? 0 );
                $variant['price']      = (float) ( $existing->rate ?? 0 );
                $variant['unit_id']    = $existing->unit_id;
                $variant['size_id']    = $existing->size_id;
                $variant['color_id']   = $existing->color_id;
                $syncedJson[]          = $variant;
            }

            // Remove DB rows no longer present in the payload.
            ProductVariant::where( 'product_id', $product->id )
                ->whereNotIn( 'id', $processedIds )
                ->delete();

            self::mergeDuplicateVariants( $product->id, $processedIds );
            $product->variants = $syncedJson;

            // Always persist variants JSON + rates mapping. Do not rely on qty recalc save,
            // which can early-return when all variant qtys are 0.
            $product->saveQuietly();
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

        $totalQty          = (int) ProductVariant::where( 'product_id', $product->id )->sum( 'qty' );
        $currentProductQty = (int) $product->qty;

        // Keep product qty when variants report 0 but product still has stock
        // (legacy purchase/stock flows). Still allow explicit zeroing when product qty is already 0.
        if ( $totalQty === 0 && $currentProductQty > 0 ) {
            return;
        }

        if ( (string) $product->qty === (string) max( 0, $totalQty ) ) {
            return;
        }

        $product->qty = (string) max( 0, $totalQty );
        $product->saveQuietly();
    }

    public static function decrementStockOnConnection(
        string $connectionName,
        int $productId,
        ?int $unitId,
        ?int $sizeId,
        ?int $colorId,
        int $qty,
        ?int $variantId = null,
        ?int $vendorId = null
    ): void {
        if ( $qty <= 0 ) {
            return;
        }

        DB::connection( $connectionName )->transaction( function () use (
            $connectionName,
            $productId,
            $unitId,
            $sizeId,
            $colorId,
            $qty,
            $variantId,
            $vendorId
        ) {
            $variantRow = self::findVariantRowOnConnection( $connectionName, $productId, $unitId, $sizeId, $colorId, $variantId );

            if ( $variantRow ) {
                DB::connection( $connectionName )->table( 'product_variants' )
                    ->where( 'id', $variantRow->id )
                    ->update( ['qty' => max( 0, (int) $variantRow->qty - $qty )] );
            } else {
                $productRow = DB::connection( $connectionName )->table( 'products' )
                    ->where( 'id', $productId )
                    ->first();

                if ( $productRow ) {
                    DB::connection( $connectionName )->table( 'products' )
                        ->where( 'id', $productId )
                        ->update( ['qty' => max( 0, (int) $productRow->qty - $qty )] );
                }

                return;
            }

            $productRow = DB::connection( $connectionName )->table( 'products' )
                ->where( 'id', $productId )
                ->first();

            if ( $productRow ) {
                DB::connection( $connectionName )->table( 'products' )
                    ->where( 'id', $productId )
                    ->update( ['qty' => max( 0, (int) $productRow->qty - $qty )] );
            }

            self::syncJsonFromDbOnConnection( $connectionName, $productId );
        } );
    }

    public static function incrementStockOnConnection(
        string $connectionName,
        int $productId,
        ?int $unitId,
        ?int $sizeId,
        ?int $colorId,
        int $qty,
        ?int $variantId = null,
        ?int $vendorId = null
    ): void {
        if ( $qty <= 0 ) {
            return;
        }

        DB::connection( $connectionName )->transaction( function () use (
            $connectionName,
            $productId,
            $unitId,
            $sizeId,
            $colorId,
            $qty,
            $variantId,
            $vendorId
        ) {
            $variantRow = self::findVariantRowOnConnection( $connectionName, $productId, $unitId, $sizeId, $colorId, $variantId );

            if ( $variantRow ) {
                DB::connection( $connectionName )->table( 'product_variants' )
                    ->where( 'id', $variantRow->id )
                    ->update( ['qty' => (int) $variantRow->qty + $qty ] );
            } else {
                DB::connection( $connectionName )->table( 'product_variants' )->insert( [
                    'user_id'    => $vendorId ?? 0,
                    'product_id' => $productId,
                    'unit_id'    => $unitId,
                    'size_id'    => $sizeId,
                    'color_id'   => $colorId,
                    'qty'        => $qty,
                    'rate'       => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ] );
            }

            self::syncJsonFromDbOnConnection( $connectionName, $productId );
            self::recalculateProductQtyOnConnection( $connectionName, $productId, true );
        } );
    }

    public static function getEffectiveQty( int $productId ): int {
        $product = Product::find( $productId );

        if ( ! $product ) {
            return 0;
        }

        $variantTotal = (int) ProductVariant::where( 'product_id', $productId )->sum( 'qty' );
        $productQty   = (int) $product->qty;

        if ( ProductVariant::where( 'product_id', $productId )->exists() ) {
            return max( $productQty, $variantTotal );
        }

        return max( 0, $productQty );
    }

    private static function findVariantRowOnConnection(
        string $connectionName,
        int $productId,
        ?int $unitId,
        ?int $sizeId,
        ?int $colorId,
        ?int $variantId = null
    ): ?object {
        if ( $variantId ) {
            $variant = DB::connection( $connectionName )->table( 'product_variants' )
                ->where( 'product_id', $productId )
                ->where( 'id', $variantId )
                ->first();

            if ( $variant ) {
                return $variant;
            }
        }

        $query = DB::connection( $connectionName )->table( 'product_variants' )
            ->where( 'product_id', $productId );

        self::applyNullableColumnFilter( $query, 'unit_id', $unitId );
        self::applyNullableColumnFilter( $query, 'size_id', $sizeId );
        self::applyNullableColumnFilter( $query, 'color_id', $colorId );

        return $query->first();
    }

    private static function applyNullableColumnFilter( $query, string $column, ?int $value ): void {
        if ( $value === null ) {
            $query->whereNull( $column );
        } else {
            $query->where( $column, $value );
        }
    }

    private static function recalculateProductQtyOnConnection( string $connectionName, int $productId, bool $force = false ): void {
        $variantCount = DB::connection( $connectionName )->table( 'product_variants' )
            ->where( 'product_id', $productId )
            ->count();

        if ( $variantCount === 0 ) {
            return;
        }

        $totalQty = (int) DB::connection( $connectionName )->table( 'product_variants' )
            ->where( 'product_id', $productId )
            ->sum( 'qty' );

        $currentProductQty = (int) ( DB::connection( $connectionName )->table( 'products' )
            ->where( 'id', $productId )
            ->value( 'qty' ) ?? 0 );

        if ( ! $force && $totalQty === 0 && $currentProductQty > 0 ) {
            return;
        }

        DB::connection( $connectionName )->table( 'products' )
            ->where( 'id', $productId )
            ->update( ['qty' => (string) max( 0, $totalQty )] );
    }

    private static function syncJsonFromDbOnConnection( string $connectionName, int $productId ): void {
        $productRow = DB::connection( $connectionName )->table( 'products' )
            ->where( 'id', $productId )
            ->first();

        if ( ! $productRow ) {
            return;
        }

        $dbVariants = DB::connection( $connectionName )->table( 'product_variants' )
            ->where( 'product_id', $productId )
            ->get( ['id', 'unit_id', 'size_id', 'color_id', 'qty', 'rate'] );

        if ( $dbVariants->isEmpty() ) {
            return;
        }

        $jsonVariants = [];

        if ( ! empty( $productRow->variants ) ) {
            $decoded = is_string( $productRow->variants )
                ? json_decode( $productRow->variants, true )
                : $productRow->variants;
            $jsonVariants = is_array( $decoded ) ? $decoded : [];
        }

        $updatedJson = [];

        foreach ( $dbVariants as $dbVariant ) {
            $matched = false;

            foreach ( $jsonVariants as $jsonVariant ) {
                $jsonVariant = (array) $jsonVariant;

                if (
                    self::normalizeNullableId( self::resolveAttributeId( $jsonVariant, 'unit', 'unit_id' ) ) === self::normalizeNullableId( $dbVariant->unit_id )
                    && self::normalizeNullableId( self::resolveSizeId( $jsonVariant ) ) === self::normalizeNullableId( $dbVariant->size_id )
                    && self::normalizeNullableId( self::resolveAttributeId( $jsonVariant, 'color', 'color_id' ) ) === self::normalizeNullableId( $dbVariant->color_id )
                ) {
                    $jsonVariant['id']         = $dbVariant->id;
                    $jsonVariant['variant_id'] = $dbVariant->id;
                    $jsonVariant['qty']        = max( 0, (int) $dbVariant->qty );
                    $jsonVariant['rate']       = (float) ( $dbVariant->rate ?? 0 );
                    $jsonVariant['price']      = (float) ( $dbVariant->rate ?? 0 );
                    $jsonVariant['unit_id']    = $dbVariant->unit_id;
                    $jsonVariant['size_id']    = $dbVariant->size_id;
                    $jsonVariant['color_id']   = $dbVariant->color_id;
                    $updatedJson[]             = $jsonVariant;
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
                    'rate'       => (float) ( $dbVariant->rate ?? 0 ),
                    'price'      => (float) ( $dbVariant->rate ?? 0 ),
                ];
            }
        }

        DB::connection( $connectionName )->table( 'products' )
            ->where( 'id', $productId )
            ->update( ['variants' => json_encode( $updatedJson )] );
    }

    public static function syncJsonFromDb( Product $product ): void {
        $dbVariants = ProductVariant::where( 'product_id', $product->id )
            ->get( ['id', 'unit_id', 'size_id', 'color_id', 'qty', 'rate'] );

        if ( $dbVariants->isEmpty() ) {
            return;
        }

        $jsonVariants = is_array( $product->variants ) ? $product->variants : [];
        $updatedJson  = [];
        $usedJsonIdx  = [];

        foreach ( $dbVariants as $dbVariant ) {
            $matchedIdx = null;

            // 1) Prefer exact id / variant_id match (required for multi option-based variants).
            foreach ( $jsonVariants as $idx => $jsonVariant ) {
                if ( isset( $usedJsonIdx[$idx] ) ) {
                    continue;
                }

                $jsonVariant = (array) $jsonVariant;
                $jsonId      = (int) ( $jsonVariant['variant_id'] ?? $jsonVariant['id'] ?? 0 );

                if ( $jsonId > 0 && $jsonId === (int) $dbVariant->id ) {
                    $matchedIdx = $idx;
                    break;
                }
            }

            // 2) Fall back to unit/size/color only when at least one attr is present.
            if ( $matchedIdx === null && ( $dbVariant->unit_id || $dbVariant->size_id || $dbVariant->color_id ) ) {
                foreach ( $jsonVariants as $idx => $jsonVariant ) {
                    if ( isset( $usedJsonIdx[$idx] ) ) {
                        continue;
                    }

                    $jsonVariant = (array) $jsonVariant;

                    if ( self::variantAttributesMatch( $jsonVariant, $dbVariant ) ) {
                        $matchedIdx = $idx;
                        break;
                    }
                }
            }

            // 3) Option-based (null attrs): keep first unused JSON row in order so
            // key/label/sku/options survive reconcile when ids were omitted.
            if ( $matchedIdx === null && ! $dbVariant->unit_id && ! $dbVariant->size_id && ! $dbVariant->color_id ) {
                foreach ( $jsonVariants as $idx => $jsonVariant ) {
                    if ( isset( $usedJsonIdx[$idx] ) ) {
                        continue;
                    }

                    $matchedIdx = $idx;
                    break;
                }
            }

            if ( $matchedIdx !== null ) {
                $jsonVariant                   = (array) $jsonVariants[$matchedIdx];
                $jsonVariant['id']             = $dbVariant->id;
                $jsonVariant['variant_id']     = $dbVariant->id;
                $jsonVariant['qty']            = max( 0, (int) $dbVariant->qty );
                // DB rate is source of truth after sync; keep JSON metadata (key/label/options).
                $jsonVariant['rate']           = (float) ( $dbVariant->rate ?? 0 );
                $jsonVariant['price']          = (float) ( $dbVariant->rate ?? 0 );
                $jsonVariant['unit_id']        = $dbVariant->unit_id;
                $jsonVariant['size_id']        = $dbVariant->size_id;
                $jsonVariant['color_id']       = $dbVariant->color_id;
                $updatedJson[]                 = $jsonVariant;
                $usedJsonIdx[$matchedIdx]      = true;
            } else {
                $updatedJson[] = [
                    'id'         => $dbVariant->id,
                    'variant_id' => $dbVariant->id,
                    'unit_id'    => $dbVariant->unit_id,
                    'size_id'    => $dbVariant->size_id,
                    'color_id'   => $dbVariant->color_id,
                    'qty'        => max( 0, (int) $dbVariant->qty ),
                    'rate'       => (float) ( $dbVariant->rate ?? 0 ),
                    'price'      => (float) ( $dbVariant->rate ?? 0 ),
                ];
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

        foreach ( $groups as $groupKey => $group ) {
            // Option-based variants share null/null/null attrs — never merge those.
            if ( $groupKey === 'null-null-null' ) {
                foreach ( $group as $variant ) {
                    $variant->qty = max( 0, (int) $variant->qty );
                    $variant->save();
                }

                continue;
            }

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
