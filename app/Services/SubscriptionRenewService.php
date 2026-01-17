<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponUsed;
use App\Models\PaymentStore;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\VendorService;
use Carbon\Carbon;

/**
 * Class SubscriptionRenewService.
 */
class SubscriptionRenewService {
    static function renew( $validatedData ) {

        $user = User::find( userid() );
        if ( !$user->usersubscription ) {
            if ( $user->role_as == 2 || $user->role_as == 3 ) {
                return responsejson( 'You have not subscription.', 'fail' );
            }
        }

        $subscriptionid  = $validatedData['package_id'];
        $trxid           = uniqid();
        $getsubscription = Subscription::find( $subscriptionid );

        // subscription due balance - membership credit
        $subscriptiondue = ( SubscriptionDueService::subscriptiondue( auth()->id() ) - SubscriptionDueService::membership_credit( auth()->id(), $subscriptionid ) );

        $getusertype    = userrole( $user->role_as );
        $servicecreated = VendorService::where( 'user_id', auth()->id() )->count();

        if ( $getusertype == 'vendor' ) {
            $productcreated   = Product::where( 'user_id', auth()->id() )->count();
            $affiliaterequest = ProductDetails::where( ['vendor_id' => auth()->id(), 'status' => 1] )->count();

            if ( $getsubscription->service_qty < $servicecreated ) {
                $qty = $servicecreated - $getsubscription->service_qty;
                return responsejson( 'You can not renew now. You should delete ' . $qty . ' service', 'fail' );
            }

            if ( $getsubscription->product_qty < $productcreated ) {
                $qty = $productcreated - $getsubscription->product_qty;
                return responsejson( 'You can not renew now. You should delete ' . $qty . ' product ', 'fail' );
            }

            if ( $getsubscription->affiliate_request < $affiliaterequest ) {
                $qty = $affiliaterequest - $getsubscription->affiliate_request;
                return responsejson( 'You can not renew now. You should delete ' . $qty . ' product request ', 'fail' );
            }
        }

        if ( $getusertype == 'affiliate' ) {

            if ( $getsubscription->service_create < $servicecreated ) {
                $qty = $servicecreated - $getsubscription->service_create;
                return responsejson( 'You can not renew now. You should delete ' . $qty . ' service', 'fail' );
            }
            $product_request = ProductDetails::where( 'user_id', auth()->id() )->count();
            $product_approve = ProductDetails::where( ['user_id' => auth()->id(), 'status' => 1] )->count();

            if ( $getsubscription->product_request < $product_request ) {
                return responsejson( 'You can not renew now. You should contact to admin', 'fail' );
            }

            if ( $getsubscription->product_approve < $product_approve ) {
                return responsejson( 'You can not renew now. You should contact to admin', 'fail' );
            }
        }

        $totalprice = $getsubscription->subscription_amount + $subscriptiondue;

        if ( request( 'coupon_id' ) != '' ) {

            $coupondata = couponget( request( 'coupon_id' ) );
            if ( !$coupondata ) {
                return responsejson( 'Invaild coupon', 'fail' );
            }

            if ( $coupondata->type == 'flat' ) {
                $totalprice = ( $totalprice - $coupondata->amount );
            } else {
                $totalprice = ( $totalprice - ( ( $totalprice / 100 ) * $coupondata->amount ) );
            }

            if ( $totalprice < 1 ) {
                return responsejson( 'You can not use this coupon!', 'fail' );
            }
        }

        if ( request( 'payment_method' ) == 'my-wallet' ) {
            $userbalance = $user->balance;
            if ( request( 'package_id' ) ) {
                if ( $userbalance < ( $totalprice ) ) {
                    return responsejson( 'You have not enough balance. You should recharge', 'fail' );
                }
            }
        }

        if ( $validatedData['payment_method'] == 'my-wallet' ) {
            $user->balance = convertfloat( $user->balance ) - ( $totalprice );
            $user->save();
            return self::subscriptionadd( $user, $subscriptionid, $trxid, 'My wallet', 'Renew', $totalsubscriptionamount = $totalprice, $couponName = request( 'coupon_id' ) );
        }

        if ( $validatedData['payment_method'] == 'aamarpay' ) {
            $successurl = url( 'api/aaparpay/renew-success' );

            // $setting = Settings::first(); //For Extra charge

            //For Extra charge
            // if ($setting->extra_charge_status == "on") {
            //     $extra_charge = extraCharge($totalprice, $setting->extra_charge);

            //     $totalprice = $totalprice + $extra_charge;
            // }

            $validatedData['user_id'] = auth()->id();
            $validatedData['coupon']  = request( 'coupon_id' );
            // $validatedData['extra_charge'] = $extra_charge;
            PaymentStore::create( [
                'payment_gateway' => 'aamarpay',
                'trxid'           => $trxid,
                'status'          => 'pending',
                'payment_type'    => 'renew',
                'info'            => $validatedData,
            ] );

            $tenant_type = function_exists( 'tenant' ) && tenant() ? 'tenant' : 'user';
            return AamarPayService::gateway( $totalprice, $trxid, 'renew', $successurl, $tenant_type );
        }
    }

    // $trxid, $amount, $payment_method, $transition_type, $balance_type, $coupon, $userid
    static function subscriptionadd( $user, $subscriptionid, $trxid, $payment_method, $transition_type, $totalsubscriptionamount = null, $couponName = '' ) {
        $userCurrentSubscription = $user->usersubscription;
        $getsubscription         = Subscription::find( $subscriptionid );
        $usersubscriptionPlan    = Subscription::find( $userCurrentSubscription->subscription_id );
        $addMonth                = getmonth( $getsubscription->subscription_package_type );

        PaymentHistoryService::store( $trxid, ( $totalsubscriptionamount ?? $getsubscription->subscription_amount ), $payment_method, $transition_type, '-', ( $couponName ), $user->id );

        // PaymentHistoryService::store($trxid, $totalamount, $paymentmethod, 'Subscription', '-', $coupon, $user->id);
        $getcoupon = Coupon::find( $couponName ?? 0 );

        if ( $getcoupon ) {
            $couponUser = User::find( $getcoupon->user_id );

            if ( $getcoupon->commission_type == "flat" ) {
                $commission = $getcoupon->commission;
            } else {
                $commission = ( ( $totalsubscriptionamount / 100 ) * $getcoupon->commission );
            }

            $couponUser->increment( 'balance', $commission );

            CouponUsed::create( [
                'user_id'          => $getcoupon->user_id,
                'coupon_id'        => $couponName,
                'total_commission' => $commission,
            ] );

            PaymentHistoryService::store( $trxid, $commission, 'My wallet', 'Referral bonus', '+', $couponName, $couponUser->id );
        }

        $userCurrentSubscription->subscription_price = $getsubscription->subscription_amount;

        if ( $getsubscription->id == $usersubscriptionPlan->id ) {
            if ( $userCurrentSubscription->expire_date > now() ) {
                $expiretime = Carbon::parse( $userCurrentSubscription->expire_date )->addMonth( $addMonth );
            } else {
                $expiretime = now()->addMonth( $addMonth );
            }

            $userCurrentSubscription->expire_date       = $expiretime;
            $userCurrentSubscription->service_qty       = $getsubscription->service_qty;
            $userCurrentSubscription->product_qty       = $getsubscription->product_qty;
            $userCurrentSubscription->affiliate_request = $getsubscription->affiliate_request;
            $userCurrentSubscription->product_request   = $getsubscription->product_request;
            $userCurrentSubscription->product_approve   = $getsubscription->product_approve;
            $userCurrentSubscription->service_create    = $getsubscription->service_create;
            $userCurrentSubscription->save();

            return responsejson( 'Renew successfully' );
        } else {
            $expiredate = now()->addMonth( $addMonth );

            $userCurrentSubscription->expire_date     = $expiredate;
            $userCurrentSubscription->subscription_id = $getsubscription->id;

            if ( userrole( $user->role_as ) == 'vendor' ) {
                $userCurrentSubscription->service_qty       = $getsubscription->service_qty;
                $userCurrentSubscription->product_qty       = $getsubscription->product_qty;
                $userCurrentSubscription->affiliate_request = $getsubscription->affiliate_request;
                $userCurrentSubscription->pos_sale_qty      = $getsubscription->pos_sale_qty;
                $userCurrentSubscription->employee_create   = $getsubscription->employee_create;
                $userCurrentSubscription->chat_access       = $getsubscription->chat_access;
            }

            if ( userrole( $user->role_as ) == 'affiliate' ) {
                $userCurrentSubscription->product_request = $getsubscription->product_request;
                $userCurrentSubscription->product_approve = $getsubscription->product_approve;
                $userCurrentSubscription->service_create  = $getsubscription->service_create;
                $userCurrentSubscription->chat_access     = $getsubscription->chat_access;
            }
            $userCurrentSubscription->save();

            return responsejson( "Subscription upgrade successfully!" );
        }
    }
}
