<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponUsed;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Notifications\SubscriptionNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Class SubscriptionService.
 */
class SubscriptionService {
    static function store( $subscription, $user, $totalamount = null, $coupon = null, $paymentmethod = null ) {
        $trxid        = uniqid();
        $subscription = Subscription::find( $subscription->id );

        $userSubscription                     = new UserSubscription();
        $userSubscription->user_id            = $user->id;
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

        PaymentHistoryService::store( $trxid, $totalamount, $paymentmethod, 'Subscription', '-', $coupon, $user->id );
        $getcoupon = Coupon::find( $coupon );

        if ( $getcoupon ) {
            $couponUser        = User::find( $getcoupon->user_id );
            $totalreffralBonus = colculateflatpercentage( $getcoupon->commission_type, $subscription->subscription_amount, $getcoupon->commission );
            $couponUser->increment( 'balance', $totalreffralBonus );

            CouponUsed::create( [
                'user_id'          => $getcoupon->user_id,
                'coupon_id'        => $coupon,
                'total_commission' => $totalreffralBonus,
            ] );

            PaymentHistoryService::store( $trxid, $totalreffralBonus, 'My wallet', 'Referral bonus', '+', $coupon, $couponUser->id );
        }

        if ( userrole( $user->role_as ) == 'user' ) {

            $getuser = User::find( $user->id );
            if ( $subscription->subscription_user_type == "vendor" ) {
                $getuser->role_as = 2;
            }

            if ( $subscription->subscription_user_type == "affiliate" ) {
                $getuser->role_as = 3;
            }
            $getuser->save();

            return $getuser->role_as;

        }

        //For user
        $subscriptionText = "Congratulations! Your package was successfully purchased!";
        Notification::send( $user, new SubscriptionNotification( $user, $subscriptionText ) );

        //For admin
        $normalUser       = $user; // Vendor or affiliate
        $user             = User::where( 'role_as', 1 )->first(); //Admin
        $subscriptionText = $normalUser->email . "Purchase a new package";
        Notification::send( $user, new SubscriptionNotification( $user, $subscriptionText ) );
        return responsejson( 'Successfull', 'success' );
    }
}
