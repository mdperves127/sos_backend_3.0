<?php

namespace App\Services;

/**
 * Dropshipper custom selling price rules.
 *
 * Merchant price (base) is never modified; the dropshipper's price is stored on
 * product_details.selling_price and the margin on product_details.profit_amount.
 */
class DropshipperPricingService {

    public const MODE_RANGE = 'range';
    public const MODE_FIXED = 'fixed';

    private const AMOUNT_PATTERN = '/^\d{1,10}(\.\d{1,2})?$/';

    /**
     * Merchant unit price for single purchase (same rule as cart: discount price wins).
     */
    public static function basePrice( object $product ): float {
        $price = ( $product->discount_price ?? null ) === null || $product->discount_price === ''
            ? ( $product->selling_price ?? 0 )
            : $product->discount_price;

        return round( (float) $price, 2 );
    }

    /**
     * Parse a money value; null when malformed, negative, or more than 2 decimals.
     */
    public static function parseAmount( mixed $value ): ?float {
        if ( is_int( $value ) || is_float( $value ) ) {
            $value = number_format( (float) $value, 2, '.', '' );
        }

        if ( ! is_string( $value ) ) {
            return null;
        }

        $value = trim( $value );
        if ( ! preg_match( self::AMOUNT_PATTERN, $value ) ) {
            return null;
        }

        return round( (float) $value, 2 );
    }

    /**
     * @return array{allow_custom: bool, mode: string, base_price: float, min_price: ?float, max_price: ?float, fixed_price: ?float, lower_bound: float, upper_bound: ?float}
     */
    public static function rules( object $product ): array {
        $base  = self::basePrice( $product );
        $mode  = ( $product->dropshipper_price_mode ?? self::MODE_RANGE ) === self::MODE_FIXED ? self::MODE_FIXED : self::MODE_RANGE;
        $min   = self::nullableAmount( $product->dropshipper_min_price ?? null );
        $max   = self::nullableAmount( $product->dropshipper_max_price ?? null );
        $fixed = self::nullableAmount( $product->dropshipper_fixed_price ?? null );

        return [
            'allow_custom' => ! isset( $product->allow_custom_selling_price ) || (int) $product->allow_custom_selling_price === 1,
            'mode'         => $mode,
            'base_price'   => $base,
            'min_price'    => $min,
            'max_price'    => $max,
            'fixed_price'  => $fixed,
            'lower_bound'  => max( $base, $min ?? 0 ),
            'upper_bound'  => $max,
        ];
    }

    /**
     * Price the dropshipper must use when they cannot choose one, or null when custom is allowed.
     */
    public static function enforcedPrice( array $rules ): ?float {
        if ( $rules['mode'] === self::MODE_FIXED ) {
            return $rules['fixed_price'] ?? $rules['base_price'];
        }

        if ( ! $rules['allow_custom'] ) {
            return $rules['base_price'];
        }

        return null;
    }

    /**
     * Validate a dropshipper-submitted selling price.
     *
     * @return array{ok: bool, price: ?float, message: ?string}
     */
    public static function validateSellingPrice( object $product, mixed $value ): array {
        $rules = self::rules( $product );
        $price = self::parseAmount( $value );

        if ( $price === null || $price <= 0 ) {
            return self::fail( 'Selling price must be a positive amount with at most 2 decimal places.' );
        }

        $enforced = self::enforcedPrice( $rules );
        if ( $enforced !== null ) {
            if ( abs( $price - $enforced ) < 0.005 ) {
                return ['ok' => true, 'price' => $enforced, 'message' => null];
            }

            return self::fail( $rules['mode'] === self::MODE_FIXED
                ? 'This product has a fixed selling price of ' . number_format( $enforced, 2, '.', '' ) . '.'
                : 'The merchant does not allow a custom selling price for this product.' );
        }

        if ( $price < $rules['lower_bound'] ) {
            return self::fail( 'Selling price must be at least ' . number_format( $rules['lower_bound'], 2, '.', '' ) . '.' );
        }

        if ( $rules['upper_bound'] !== null && $price > $rules['upper_bound'] ) {
            return self::fail( 'Selling price must not exceed ' . number_format( $rules['upper_bound'], 2, '.', '' ) . '.' );
        }

        return ['ok' => true, 'price' => $price, 'message' => null];
    }

    /**
     * Unit selling price to use for an order, re-evaluated against the merchant's current rules.
     */
    public static function effectiveSellingPrice( object $product, ?object $detail ): float {
        $rules    = self::rules( $product );
        $enforced = self::enforcedPrice( $rules );
        if ( $enforced !== null ) {
            return $enforced;
        }

        $stored = self::nullableAmount( $detail->selling_price ?? null );
        $price  = $stored ?? round( $rules['base_price'] + max( 0, (float) ( $detail->profit_amount ?? 0 ) ), 2 );

        $price = max( $price, $rules['lower_bound'] );
        if ( $rules['upper_bound'] !== null ) {
            $price = min( $price, $rules['upper_bound'] );
        }

        return round( $price, 2 );
    }

    public static function unitMargin( object $product, ?object $detail ): float {
        return round( max( 0, self::effectiveSellingPrice( $product, $detail ) - self::basePrice( $product ) ), 2 );
    }

    /**
     * Rules payload for merchant/dropshipper UIs.
     */
    public static function publicRules( object $product ): array {
        $rules  = self::rules( $product );
        $public = $rules;
        unset( $public['lower_bound'], $public['upper_bound'] );

        $public['enforced_price'] = self::enforcedPrice( $rules );
        $public['min_allowed']    = $rules['lower_bound'];
        $public['max_allowed']    = $rules['upper_bound'];

        return $public;
    }

    private static function nullableAmount( mixed $value ): ?float {
        if ( $value === null || $value === '' ) {
            return null;
        }

        return round( (float) $value, 2 );
    }

    private static function fail( string $message ): array {
        return ['ok' => false, 'price' => null, 'message' => $message];
    }
}
