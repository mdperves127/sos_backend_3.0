<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponUsed;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Notifications\SubscriptionNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

/**
 * Class SubscriptionService.
 */
class SubscriptionService {
    static function store( $subscription, $entity, $totalamount = null, $coupon = null, $paymentmethod = null ) {
        $trxid        = uniqid();
        $subscription = Subscription::on('mysql')->find( $subscription->id );

        $userSubscription                     = new UserSubscription();
        if ( $entity instanceof User ) {
            $userSubscription->user_id = $entity->id;
        } else {
            $userSubscription->tenant_id = $entity->id;
            // For tenant, we might still want to link to the owner user if possible
            // But if it's strictly tenant-level, we leave user_id null or set a default
            $userSubscription->user_id = Auth::check() ? Auth::id() : 0;
        }
        $userSubscription->trxid              = $trxid;
        $userSubscription->subscription_id    = $subscription->id;
        $userSubscription->expire_date        = membershipexpiredate( $subscription->subscription_package_type );
        $userSubscription->subscription_price = $subscription->subscription_amount;
        $userSubscription->chat_access        = $subscription->chat_access;

        if ( $subscription->subscription_user_type == "vendor" ) {
            $userSubscription->service_qty       = $subscription->service_qty;
            $userSubscription->product_qty       = $subscription->product_qty;
            $userSubscription->affiliate_request = $subscription->affiliate_request;
            $userSubscription->pos_sale_qty      = $subscription->pos_sale_qty;
            $userSubscription->employee_create   = $subscription->employee_create;
        }

        if ( $subscription->subscription_user_type == "affiliate" ) {
            $userSubscription->product_request = $subscription->product_request;
            $userSubscription->product_approve = $subscription->product_approve;
            $userSubscription->service_create  = $subscription->service_create;
        }

        $userSubscription->save();

        if ( !$totalamount ) {
            false;
        }

        PaymentHistoryService::store( $trxid, $totalamount, $paymentmethod, 'Subscription', '-', $coupon, $entity->id );
        $getcoupon = Coupon::on('mysql')->find( $coupon );

        if ( $getcoupon ) {
            $couponUser        = User::on('mysql')->find( $getcoupon->user_id );
            $totalreffralBonus = colculateflatpercentage( $getcoupon->commission_type, $subscription->subscription_amount, $getcoupon->commission );
            $couponUser->increment( 'balance', $totalreffralBonus );

            CouponUsed::create( [
                'user_id'          => $getcoupon->user_id,
                'coupon_id'        => $coupon,
                'total_commission' => $totalreffralBonus,
            ] );

            PaymentHistoryService::store( $trxid, $totalreffralBonus, 'My wallet', 'Referral bonus', '+', $coupon, $couponUser->id );
        }

        if ( $entity instanceof User && userrole( $entity->role_as ) == 'user' ) {

            $getuser = User::on('mysql')->find( $entity->id );
            if ( $subscription->subscription_user_type == "vendor" ) {
                $getuser->role_as = 2;
            }

            if ( $subscription->subscription_user_type == "affiliate" ) {
                $getuser->role_as = 3;
            }
            $getuser->save();

            return $getuser->role_as;

        }

        //For entity (User or Tenant)
        $subscriptionText = "Congratulations! Your package was successfully purchased!";
        // If it's a tenant, we notify the tenant's email or owner
        $notificationTarget = $entity;
        if ( !( $entity instanceof User ) ) {
            // For Tenant, we might need a different notification approach or just use the Tenant model if it supports notifications
            // If Tenant doesn't support notifications, we might need to find the owner user
            // Assuming Tenant can receive notifications or we just skip it for now if not applicable
        }

        try {
            Notification::send( $notificationTarget, new SubscriptionNotification( $notificationTarget, $subscriptionText ) );
        } catch (\Exception $e) {
            \Log::error("Failed to send subscription notification: " . $e->getMessage());
        }

        //For admin
        $normalUser       = $entity; // Vendor or affiliate or Tenant
        $admin            = User::on('mysql')->where( 'role_as', 1 )->first(); //Admin
        $entityName       = ($entity instanceof User) ? $entity->email : ($entity->company_name ?? $entity->id);
        $subscriptionText = $entityName . " Purchase a new package";
        Notification::send( $admin, new SubscriptionNotification( $admin, $subscriptionText ) );
        return responsejson( 'Successfull', 'success' );
    }
}
