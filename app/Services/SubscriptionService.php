<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponUsed;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSubscription;
use App\Notifications\SubscriptionNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/**
 * Class SubscriptionService.
 */
class SubscriptionService {
    public static function applyPlanToUserSubscription( UserSubscription $userSubscription, Subscription $subscription ): void {
        $subscription = Subscription::on( 'mysql' )->find( $subscription->id );
        $userType     = $subscription->subscription_user_type;

        $userSubscription->subscription_id    = $subscription->id;
        $userSubscription->subscription_price = $subscription->subscription_amount;
        $userSubscription->chat_access        = $subscription->chat_access;
        $userSubscription->has_website        = $subscription->has_website ?? 'no';
        $userSubscription->website_visits     = $subscription->website_visits ?? 0;
        $userSubscription->already_visits     = 0;

        if ( $userType === 'vendor' ) {
            $userSubscription->service_qty       = $subscription->service_qty;
            $userSubscription->product_qty       = $subscription->product_qty;
            $userSubscription->affiliate_request = $subscription->affiliate_request;
            $userSubscription->pos_sale_qty      = $subscription->pos_sale_qty;
            $userSubscription->employee_create   = $subscription->employee_create;
            $userSubscription->product_request   = null;
            $userSubscription->product_approve   = null;
            $userSubscription->service_create    = null;
        }

        if ( $userType === 'affiliate' ) {
            $userSubscription->product_request   = $subscription->product_request;
            $userSubscription->product_approve   = $subscription->product_approve;
            $userSubscription->service_create    = $subscription->service_create;
            $userSubscription->service_qty       = null;
            $userSubscription->product_qty       = null;
            $userSubscription->affiliate_request = null;
            $userSubscription->pos_sale_qty      = null;
            $userSubscription->employee_create   = null;
        }
    }

    public static function findLatestUserSubscription( $entity ): ?UserSubscription {
        if ( $entity instanceof Tenant ) {
            return UserSubscription::on( 'mysql' )
                ->where( 'tenant_id', $entity->id )
                ->latest( 'id' )
                ->first();
        }

        if ( $entity instanceof User ) {
            return UserSubscription::on( 'mysql' )
                ->where( 'user_id', $entity->id )
                ->whereNull( 'tenant_id' )
                ->latest( 'id' )
                ->first();
        }

        return null;
    }

    static function store( $subscription, $entity, $totalamount = null, $coupon = null, $paymentmethod = null, $actingUserId = null ) {
        $trxid        = uniqid();
        $subscription = Subscription::on('mysql')->find( $subscription->id );
        $existing     = self::findLatestUserSubscription( $entity );

        if ( $existing ) {
            $existing->trxid       = $trxid;
            $existing->expire_date = membershipexpiredate( $subscription->subscription_package_type );
            self::applyPlanToUserSubscription( $existing, $subscription );
            $existing->save();

            return self::finalizeSubscriptionPurchase(
                $entity,
                $subscription,
                $trxid,
                $totalamount,
                $coupon,
                $paymentmethod,
                $actingUserId
            );
        }

        $userSubscription = new UserSubscription();
        if ( $entity instanceof Tenant ) {
            $userSubscription->tenant_id = $entity->id;
            $userSubscription->user_id   = $actingUserId ?? ( Auth::check() ? Auth::id() : null );
        } elseif ( $entity instanceof User ) {
            $userSubscription->user_id   = $entity->id;
            $userSubscription->tenant_id = null;
        } else {
            throw new \InvalidArgumentException( 'Invalid subscription entity provided.' );
        }

        $userSubscription->trxid       = $trxid;
        $userSubscription->expire_date   = membershipexpiredate( $subscription->subscription_package_type );
        self::applyPlanToUserSubscription( $userSubscription, $subscription );
        $userSubscription->save();

        return self::finalizeSubscriptionPurchase(
            $entity,
            $subscription,
            $trxid,
            $totalamount,
            $coupon,
            $paymentmethod,
            $actingUserId
        );
    }

    protected static function finalizeSubscriptionPurchase(
        $entity,
        Subscription $subscription,
        string $trxid,
        $totalamount = null,
        $coupon = null,
        $paymentmethod = null,
        $actingUserId = null
    ) {
        if ( !$totalamount ) {
            false;
        }

        $paymentHistoryContext = $entity instanceof Tenant
            ? [
                'entity_type' => 'tenant',
                'tenant_id'   => $entity->id,
                'user_id'     => $actingUserId ?? ( Auth::check() ? Auth::id() : null ),
            ]
            : [
                'entity_type' => 'user',
                'user_id'     => $entity->id,
            ];

        PaymentHistoryService::store( $trxid, $totalamount, $paymentmethod, 'Subscription', '-', $coupon, $entity->id, $paymentHistoryContext );
        $getcoupon = Coupon::on('mysql')->find( $coupon );

        if ( $getcoupon ) {
            $totalreffralBonus = colculateflatpercentage( $getcoupon->commission_type, $subscription->subscription_amount, $getcoupon->commission );
            self::creditCouponReferralBonus( $getcoupon, $totalreffralBonus, $trxid, $coupon );
        }

        if ( $entity instanceof User && userrole( $entity->role_as ) == 'user' ) {
            $getuser = User::on('mysql')->find( $entity->id );

            if ( $subscription->subscription_user_type == 'vendor' ) {
                $getuser->role_as = 2;
            }

            if ( $subscription->subscription_user_type == 'affiliate' ) {
                $getuser->role_as = 3;
            }

            $getuser->save();

            return $getuser->role_as;
        }

        $subscriptionText   = 'Congratulations! Your package was successfully purchased!';
        $notificationTarget = $entity;

        try {
            Notification::send( $notificationTarget, new SubscriptionNotification( $notificationTarget, $subscriptionText ) );
        } catch ( \Exception $e ) {
            \Log::error( 'Failed to send subscription notification: ' . $e->getMessage() );
        }

        $admin            = User::on('mysql')->where( 'role_as', 1 )->first();
        $entityName       = ( $entity instanceof User ) ? $entity->email : ( $entity->company_name ?? $entity->id );
        $subscriptionText = $entityName . ' Purchase a new package';
        Notification::send( $admin, new SubscriptionNotification( $admin, $subscriptionText ) );

        return responsejson( 'Successfull', 'success' );
    }

    /**
     * Credit coupon referral commission to the coupon owner (tenant wallet preferred, else user wallet).
     */
    public static function creditCouponReferralBonus( Coupon $coupon, $amount, string $trxid, $couponId ): void {
        $amount = (float) $amount;
        if ( $amount <= 0 ) {
            return;
        }

        $couponTenant = ! empty( $coupon->tenant_id )
            ? Tenant::on( 'mysql' )->find( $coupon->tenant_id )
            : null;
        $couponUser = ! empty( $coupon->user_id )
            ? User::on( 'mysql' )->find( $coupon->user_id )
            : null;

        if ( $couponTenant ) {
            $couponTenant->increment( 'balance', $amount );
            self::recordCouponUsage( $couponUser?->id, $couponTenant->id, $couponId, $amount );

            PaymentHistoryService::store(
                $trxid,
                $amount,
                'My wallet',
                'Referral bonus',
                '+',
                $couponId,
                $couponTenant->id,
                [
                    'entity_type' => 'tenant',
                    'tenant_id'   => $couponTenant->id,
                    'user_id'     => $couponUser?->id,
                ]
            );

            return;
        }

        if ( ! $couponUser ) {
            \Log::warning( 'Coupon referral bonus skipped: no tenant or user owner found.', [
                'coupon_id' => $couponId,
                'user_id'   => $coupon->user_id,
                'tenant_id' => $coupon->tenant_id,
            ] );

            return;
        }

        $couponUser->increment( 'balance', $amount );
        self::recordCouponUsage( $couponUser->id, null, $couponId, $amount );

        PaymentHistoryService::store(
            $trxid,
            $amount,
            'My wallet',
            'Referral bonus',
            '+',
            $couponId,
            $couponUser->id,
            [
                'entity_type' => 'user',
                'user_id'     => $couponUser->id,
            ]
        );
    }

    protected static function recordCouponUsage( $userId, $tenantId, $couponId, $amount ): void {
        $payload = [
            'coupon_id'        => $couponId,
            'total_commission' => $amount,
            'user_id'          => $userId,
        ];

        if ( Schema::connection( 'mysql' )->hasColumn( 'coupon_useds', 'tenant_id' ) ) {
            $payload['tenant_id'] = $tenantId;
        }

        try {
            CouponUsed::create( $payload );
        } catch ( \Throwable $e ) {
            \Log::error( 'Failed to record coupon usage: ' . $e->getMessage(), $payload );
        }
    }
}
