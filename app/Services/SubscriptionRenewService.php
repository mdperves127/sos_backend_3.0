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
        $isTenant = function_exists( 'tenant' ) && tenant();

        if ( $isTenant ) {
            return self::renewForTenant( $validatedData );
        }

        return self::renewForUser( $validatedData );
    }

    /**
     * Renew subscription for central (non-tenant) users.
     */
    protected static function renewForUser( $validatedData ) {
        $user = User::on( 'mysql' )->find( userid() );
        if ( ! $user->usersubscription ) {
            if ( $user->role_as == 2 || $user->role_as == 3 ) {
                return responsejson( 'You have not subscription.', 'fail' );
            }
        }

        $subscriptionid  = $validatedData['package_id'];
        $trxid           = uniqid();
        $getsubscription  = Subscription::on( 'mysql' )->find( $subscriptionid );
        $subscriptiondue = ( SubscriptionDueService::subscriptiondue( auth()->id() ) - SubscriptionDueService::membership_credit( auth()->id(), $subscriptionid ) );
        $getusertype     = userrole( $user->role_as );
        $servicecreated  = VendorService::where( 'user_id', auth()->id() )->count();

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
        $couponResult = self::applyCoupon( $totalprice );
        if ( $couponResult instanceof \Illuminate\Http\JsonResponse ) {
            return $couponResult;
        }
        $totalprice = $couponResult;

        if ( request( 'payment_method' ) == 'my-wallet' ) {
            $userbalance = $user->balance;
            if ( request( 'package_id' ) && $userbalance < $totalprice ) {
                return responsejson( 'You have not enough balance. You should recharge', 'fail' );
            }
        }

        if ( $validatedData['payment_method'] == 'my-wallet' ) {
            $user->balance = convertfloat( $user->balance ) - $totalprice;
            $user->save();
            return self::subscriptionadd( $user, $subscriptionid, $trxid, 'My wallet', 'Renew', $totalprice, request( 'coupon_id' ) );
        }

        if ( $validatedData['payment_method'] == 'aamarpay' ) {
            $successurl = url( 'api/aaparpay/renew-success' );
            $validatedData['user_id'] = auth()->id();
            $validatedData['coupon'] = request( 'coupon_id' );
            PaymentStore::create( [
                'payment_gateway' => 'aamarpay',
                'trxid'           => $trxid,
                'status'          => 'pending',
                'payment_type'    => 'renew',
                'info'            => $validatedData,
            ] );
            return AamarPayService::gateway( $totalprice, $trxid, 'renew', $successurl, 'user' );
        }
    }

    /**
     * Renew subscription for tenant (store) context.
     */
    protected static function renewForTenant( $validatedData ) {
        $tenant          = tenant();
        $usersubscription = UserSubscription::on( 'mysql' )->where( 'tenant_id', $tenant->id )->first();

        if ( ! $usersubscription && in_array( $tenant->type ?? '', [ 'merchant', 'dropshipper' ] ) ) {
            return responsejson( 'You have not subscription.', 'fail' );
        }

        $subscriptionid  = $validatedData['package_id'];
        $trxid           = uniqid();
        $getsubscription = Subscription::on( 'mysql' )->find( $subscriptionid );
        $entityId        = $tenant->id;
        $subscriptiondue = ( SubscriptionDueService::subscriptiondue( $entityId ) - SubscriptionDueService::membership_credit( $entityId, $subscriptionid ) );
        // Tenant: map type (merchant=vendor, dropshipper=affiliate) - tenant users don't have role_as
        $getusertype     = ( $tenant->type ?? 'merchant' ) === 'dropshipper' ? 'affiliate' : 'vendor';
        // vendor_services is in central DB (mysql) with tenant_id - not in tenant DB
        $servicecreated  = VendorService::on( 'mysql' )->where( 'tenant_id', $tenant->id )->count();

        if ( $getusertype == 'vendor' ) {
            $productcreated   = Product::on( 'tenant' )->where( 'user_id', auth()->id() )->count();
            $affiliaterequest = ProductDetails::on( 'tenant' )->where( ['vendor_id' => auth()->id(), 'status' => 1] )->count();

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
            $product_request = ProductDetails::on( 'tenant' )->where( 'user_id', auth()->id() )->count();
            $product_approve = ProductDetails::on( 'tenant' )->where( ['user_id' => auth()->id(), 'status' => 1] )->count();

            if ( $getsubscription->product_request < $product_request ) {
                return responsejson( 'You can not renew now. You should contact to admin', 'fail' );
            }
            if ( $getsubscription->product_approve < $product_approve ) {
                return responsejson( 'You can not renew now. You should contact to admin', 'fail' );
            }
        }

        $totalprice = $getsubscription->subscription_amount + $subscriptiondue;
        $couponResult = self::applyCoupon( $totalprice );
        if ( $couponResult instanceof \Illuminate\Http\JsonResponse ) {
            return $couponResult;
        }
        $totalprice = $couponResult;

        if ( request( 'payment_method' ) == 'my-wallet' ) {
            $tenantBalance = convertfloat( $tenant->balance ?? 0 );
            if ( request( 'package_id' ) && $tenantBalance < $totalprice ) {
                return responsejson( 'You have not enough balance. You should recharge', 'fail' );
            }
        }

        if ( $validatedData['payment_method'] == 'my-wallet' ) {
            $tenant->balance = convertfloat( $tenant->balance ?? 0 ) - $totalprice;
            $tenant->save();
            return self::subscriptionadd( $tenant, $subscriptionid, $trxid, 'My wallet', 'Renew', $totalprice, request( 'coupon_id' ) );
        }

        if ( $validatedData['payment_method'] == 'aamarpay' ) {
            $successurl = url( 'api/aaparpay/renew-success' );
            $validatedData['user_id']    = auth()->id();
            $validatedData['tenant_id']  = $tenant->id;
            $validatedData['coupon']     = request( 'coupon_id' );
            $store = new PaymentStore( [
                'payment_gateway' => 'aamarpay',
                'trxid'           => $trxid,
                'status'          => 'pending',
                'payment_type'    => 'renew',
                'info'            => $validatedData,
            ] );
            $store->setConnection( 'mysql' );
            $store->save();
            return AamarPayService::gateway( $totalprice, $trxid, 'renew', $successurl, 'tenant' );
        }
    }

    /**
     * Apply coupon discount. Returns totalprice or JsonResponse on validation failure.
     */
    protected static function applyCoupon( $totalprice ) {
        if ( request( 'coupon_id' ) == '' ) {
            return $totalprice;
        }
        $coupondata = couponget( request( 'coupon_id' ) );
        if ( ! $coupondata ) {
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
        return $totalprice;
    }

    /**
     * Add/renew subscription. $entity can be User (central) or Tenant.
     */
    static function subscriptionadd( $entity, $subscriptionid, $trxid, $payment_method, $transition_type, $totalsubscriptionamount = null, $couponName = '' ) {
        $isTenant = $entity instanceof \App\Models\Tenant;
        $userCurrentSubscription = $entity->usersubscription;
        $getsubscription         = Subscription::on( 'mysql' )->find( $subscriptionid );
        $usersubscriptionPlan    = Subscription::on( 'mysql' )->find( $userCurrentSubscription->subscription_id );
        $addMonth                = getmonth( $getsubscription->subscription_package_type );
        $entityId                = $entity->id;
        // For tenant: map type to role_as (merchant=2=vendor, dropshipper=3=affiliate)
        $roleAs                  = $isTenant ? ( ( $entity->type ?? 'merchant' ) === 'dropshipper' ? 3 : 2 ) : $entity->role_as;

        PaymentHistoryService::store( $trxid, ( $totalsubscriptionamount ?? $getsubscription->subscription_amount ), $payment_method, $transition_type, '-', ( $couponName ), $entityId );

        $getcoupon = Coupon::on( 'mysql' )->find( $couponName ?? 0 );

        if ( $getcoupon ) {
            $couponUser = User::on( 'mysql' )->find( $getcoupon->user_id );

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

            if ( userrole( $roleAs ) == 'vendor' ) {
                $userCurrentSubscription->service_qty       = $getsubscription->service_qty;
                $userCurrentSubscription->product_qty       = $getsubscription->product_qty;
                $userCurrentSubscription->affiliate_request = $getsubscription->affiliate_request;
                $userCurrentSubscription->pos_sale_qty      = $getsubscription->pos_sale_qty;
                $userCurrentSubscription->employee_create   = $getsubscription->employee_create;
                $userCurrentSubscription->chat_access       = $getsubscription->chat_access;
            }

            if ( userrole( $roleAs ) == 'affiliate' ) {
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
