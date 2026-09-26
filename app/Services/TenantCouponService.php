<?php

namespace App\Services;

use App\Models\TenantCoupon;
use App\Models\TenantCouponUsage;
use Illuminate\Support\Carbon;

/**
 * Storefront coupons from tenant panel (tenant_coupons), not admin membership coupons.
 */
class TenantCouponService
{
    /**
     * @return array{coupon: TenantCoupon, discount_amount: float, payable_amount: float, discount_type: string}|array{error: string}
     */
    public static function validateForCheckout(
        string $code,
        float $orderAmount,
        ?int $userId = null,
        ?string $guestEmail = null
    ): array {
        $code = trim( $code );
        if ( $code === '' ) {
            return ['error' => 'Coupon code is required.'];
        }

        $coupon = TenantCoupon::query()
            ->whereRaw( 'LOWER(code) = ?', [strtolower( $code )] )
            ->first();

        if ( ! $coupon ) {
            return ['error' => 'Invalid coupon code.'];
        }

        if ( $coupon->status !== 'active' ) {
            return ['error' => 'This coupon is not active.'];
        }

        $now = Carbon::now();
        if ( $coupon->valid_from && $now->lt( Carbon::parse( $coupon->valid_from ) ) ) {
            return ['error' => 'This coupon is not valid yet.'];
        }
        if ( $coupon->valid_to && $now->gt( Carbon::parse( $coupon->valid_to ) ) ) {
            return ['error' => 'This coupon has expired.'];
        }

        if ( (float) $orderAmount < (float) $coupon->min_order_amount ) {
            return ['error' => 'Minimum order amount for this coupon is ' . $coupon->min_order_amount . '.'];
        }

        $totalUsed = TenantCouponUsage::where( 'tenant_coupon_id', $coupon->id )->count();
        if ( $coupon->usage_limit > 0 && $totalUsed >= (int) $coupon->usage_limit ) {
            return ['error' => 'This coupon usage limit has been reached.'];
        }

        if ( $userId ) {
            $userUsed = TenantCouponUsage::where( 'tenant_coupon_id', $coupon->id )
                ->where( 'user_id', $userId )
                ->count();
            if ( $coupon->usage_limit_per_user > 0 && $userUsed >= (int) $coupon->usage_limit_per_user ) {
                return ['error' => 'You have already used this coupon the maximum number of times.'];
            }
        } elseif ( $guestEmail ) {
            $guestUsed = TenantCouponUsage::where( 'tenant_coupon_id', $coupon->id )
                ->whereNull( 'user_id' )
                ->where( 'guest_email', strtolower( trim( $guestEmail ) ) )
                ->count();
            if ( $coupon->usage_limit_per_user > 0 && $guestUsed >= (int) $coupon->usage_limit_per_user ) {
                return ['error' => 'This coupon has already been used with this email.'];
            }
        }

        $discountType = self::normalizeDiscountType( (string) $coupon->discount_type );
        $discount     = self::calculateDiscount( $coupon, $orderAmount );
        $payable      = max( 0, round( $orderAmount - $discount, 2 ) );

        // Ensure API consumers always see a valid type (repairs legacy empty/"fixed" values in response).
        $coupon->setAttribute( 'discount_type', $discountType );

        return [
            'coupon'          => $coupon,
            'discount_type'   => $discountType,
            'discount_amount' => $discount,
            'payable_amount'  => $payable,
        ];
    }

    /**
     * Normalize incoming/stored discount type to: flat | percentage
     */
    public static function normalizeDiscountType( ?string $type ): string
    {
        $type = strtolower( trim( (string) $type ) );

        return match ( $type ) {
            'flat', 'fixed', 'fix', 'amount' => 'flat',
            'percentage', 'percent', 'perchentage', '%' => 'percentage',
            default => 'percentage',
        };
    }

    public static function isFlatDiscount( ?string $type ): bool
    {
        return self::normalizeDiscountType( $type ) === 'flat';
    }

    public static function calculateDiscount( TenantCoupon $coupon, float $orderAmount ): float
    {
        $orderAmount = max( 0, (float) $orderAmount );
        $value       = (float) $coupon->discount_value;

        if ( self::isFlatDiscount( $coupon->discount_type ) ) {
            $discount = $value;
        } else {
            // percentage: discount_value is percent (e.g. 10 = 10%)
            $discount = ( $orderAmount * $value ) / 100;
        }

        $maxDiscount = (float) ( $coupon->max_discount_amount ?? 0 );
        if ( $maxDiscount > 0 ) {
            $discount = min( $discount, $maxDiscount );
        }

        return round( min( max( 0, $discount ), $orderAmount ), 2 );
    }

    public static function recordUsage(
        TenantCoupon $coupon,
        float $discountAmount,
        ?int $orderId = null,
        ?int $userId = null,
        ?string $guestEmail = null
    ): TenantCouponUsage {
        return TenantCouponUsage::create( [
            'tenant_coupon_id' => $coupon->id,
            'order_id'         => $orderId,
            'user_id'          => $userId ?: null,
            'guest_email'      => $guestEmail ? strtolower( trim( $guestEmail ) ) : null,
            'discount_amount'  => $discountAmount,
        ] );
    }
}
