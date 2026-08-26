<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponUsed;
use App\Models\PaymentStore;
use App\Models\Product;
use App\Models\ProductDetails;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\VendorService;
use Carbon\Carbon;
use App\Services\EpsPaymentService;
use App\Helper\RedirectHelper;
use Illuminate\Support\Facades\DB;

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

        $subscriptionid   = $validatedData['package_id'];
        $trxid            = uniqid();
        $getsubscription  = Subscription::on( 'mysql' )->find( $subscriptionid );
        $getusertype      = userrole( $user->role_as );
        $servicecreated   = VendorService::where( 'user_id', auth()->id() )->count();

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

        $pricing = self::calculateRenewPayable( $getsubscription->subscription_amount, auth()->id(), $subscriptionid );
        if ( $pricing instanceof \Illuminate\Http\JsonResponse ) {
            return $pricing;
        }
        $totalprice = $pricing['payable'];

        // 100% / full coupon cover — complete renew without charging.
        if ( (float) $totalprice <= 0 ) {
            return self::subscriptionadd(
                $user,
                $subscriptionid,
                $trxid,
                request( 'coupon_id' ) ? 'Coupon' : 'Free',
                'Renew',
                0,
                request( 'coupon_id' )
            );
        }

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
            $successurl = EpsPaymentService::paymentSuccessUrl( 'renew-success' );
            $validatedData['user_id'] = auth()->id();
            $validatedData['coupon'] = request( 'coupon_id' );
            $validatedData['amount'] = $totalprice;
            $validatedData = RedirectHelper::appendPaymentReturnUrl( $validatedData );
            PaymentStore::on( 'mysql' )->create( [
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
        $usersubscription = SubscriptionService::findLatestUserSubscription( $tenant );

        if ( ! $usersubscription && in_array( $tenant->type ?? '', [ 'merchant', 'dropshipper' ] ) ) {
            return responsejson( 'You have not subscription.', 'fail' );
        }

        $subscriptionid  = $validatedData['package_id'];
        $trxid           = uniqid();
        $getsubscription = Subscription::on( 'mysql' )->find( $subscriptionid );
        $entityId        = $tenant->id;
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

        $pricing = self::calculateRenewPayable( $getsubscription->subscription_amount, $entityId, $subscriptionid );
        if ( $pricing instanceof \Illuminate\Http\JsonResponse ) {
            return $pricing;
        }
        $totalprice = $pricing['payable'];

        // 100% / full coupon cover — complete renew without charging wallet or gateway.
        if ( (float) $totalprice <= 0 ) {
            $centralTenant = Tenant::on( 'mysql' )->find( $tenant->id ) ?? $tenant;

            return self::subscriptionadd(
                $centralTenant,
                $subscriptionid,
                $trxid,
                request( 'coupon_id' ) ? 'Coupon' : 'Free',
                'Renew',
                0,
                request( 'coupon_id' )
            );
        }

        if ( request( 'payment_method' ) == 'my-wallet' || ( $validatedData['payment_method'] ?? null ) == 'my-wallet' ) {
            $centralTenant = Tenant::on( 'mysql' )->find( $tenant->id );
            $tenantBalance = (float) convertfloat( (string) ( $centralTenant->balance ?? 0 ) );
            if ( request( 'package_id' ) && $tenantBalance < $totalprice ) {
                return responsejson( 'You have not enough balance. You should recharge', 'fail' );
            }
        }

        if ( $validatedData['payment_method'] == 'my-wallet' ) {
            $amount = (float) convertfloat( (string) $totalprice );

            // Deduct from central mysql.tenants.balance only.
            // tenant()->save() / VirtualColumn often skips persisting balance during renew.
            $result = DB::connection( 'mysql' )->transaction( function () use ( $tenant, $amount ) {
                $row = DB::connection( 'mysql' )
                    ->table( 'tenants' )
                    ->where( 'id', $tenant->id )
                    ->lockForUpdate()
                    ->first( ['id', 'balance'] );

                if ( ! $row ) {
                    return responsejson( 'Tenant wallet not found.', 'fail' );
                }

                $current = (float) convertfloat( (string) ( $row->balance ?? 0 ) );
                if ( $current < $amount ) {
                    return responsejson( 'You have not enough balance. You should recharge', 'fail' );
                }

                $newBalance = $current - $amount;

                DB::connection( 'mysql' )
                    ->table( 'tenants' )
                    ->where( 'id', $tenant->id )
                    ->update( [
                        'balance'    => $newBalance,
                        'updated_at' => now(),
                    ] );

                $tenant->setAttribute( 'balance', $newBalance );

                return Tenant::on( 'mysql' )->find( $tenant->id );
            } );

            if ( $result instanceof \Illuminate\Http\JsonResponse ) {
                return $result;
            }

            return self::subscriptionadd( $result, $subscriptionid, $trxid, 'My wallet', 'Renew', $totalprice, request( 'coupon_id' ) );
        }

        if ( $validatedData['payment_method'] == 'aamarpay' ) {
            $successurl = EpsPaymentService::paymentSuccessUrl( 'renew-success' );
            $validatedData['user_id']    = auth()->id();
            $validatedData['tenant_id']  = $tenant->id;
            $validatedData['coupon']     = request( 'coupon_id' );
            $validatedData['amount']     = $totalprice;
            $validatedData               = RedirectHelper::appendPaymentReturnUrl( $validatedData );
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
     * Match UI renew math:
     * 1) apply coupon on package price
     * 2) add previous due
     * 3) subtract previous membership credit
     * 4) payable = max(0, result)
     *
     * @return array{package_amount: float, due: float, credit: float, after_coupon: float, coupon_discount: float, payable: float}|\Illuminate\Http\JsonResponse
     */
    protected static function calculateRenewPayable( $packageAmount, $entityId, $packageId )
    {
        $packageAmount = (float) convertfloat( (string) $packageAmount );
        $due           = (float) convertfloat( (string) SubscriptionDueService::subscriptiondue( $entityId ) );
        $credit        = (float) convertfloat( (string) SubscriptionDueService::membership_credit( $entityId, $packageId ) );

        $afterCoupon = self::applyCoupon( $packageAmount );
        if ( $afterCoupon instanceof \Illuminate\Http\JsonResponse ) {
            return $afterCoupon;
        }

        $afterCoupon    = (float) convertfloat( (string) $afterCoupon );
        $couponDiscount = max( 0, $packageAmount - $afterCoupon );
        $payable        = $afterCoupon + $due - $credit;

        if ( $payable < 0 ) {
            $payable = 0;
        }

        // Keep money values stable for wallet / payment history / referral.
        $payable = round( $payable, 2 );

        return [
            'package_amount'  => round( $packageAmount, 2 ),
            'due'             => round( $due, 2 ),
            'credit'          => round( $credit, 2 ),
            'after_coupon'    => round( $afterCoupon, 2 ),
            'coupon_discount' => round( $couponDiscount, 2 ),
            'payable'         => $payable,
        ];
    }

    /**
     * Apply coupon discount on package price only.
     * 100% / full-cover coupons are allowed and result in 0.
     */
    protected static function applyCoupon( $totalprice ) {
        if ( request( 'coupon_id' ) == '' || request( 'coupon_id' ) === null ) {
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

        if ( $totalprice < 0 ) {
            $totalprice = 0;
        }

        return $totalprice;
    }

    /**
     * Add/renew subscription. $entity can be User (central) or Tenant.
     */
    static function subscriptionadd( $entity, $subscriptionid, $trxid, $payment_method, $transition_type, $totalsubscriptionamount = null, $couponName = '' ) {
        $isTenant = $entity instanceof \App\Models\Tenant;
        $userCurrentSubscription = SubscriptionService::findLatestUserSubscription( $entity );

        if ( ! $userCurrentSubscription ) {
            return responsejson( 'You have not subscription.', 'fail' );
        }

        $getsubscription      = Subscription::on( 'mysql' )->find( $subscriptionid );
        $usersubscriptionPlan = Subscription::on( 'mysql' )->find( $userCurrentSubscription->subscription_id );
        $addMonth             = getmonth( $getsubscription->subscription_package_type );
        $entityId             = $entity->id;
        $paidAmount           = (float) convertfloat( (string) ( $totalsubscriptionamount ?? $getsubscription->subscription_amount ?? 0 ) );

        $paymentHistoryContext = $isTenant
            ? [
                'entity_type' => 'tenant',
                'tenant_id'   => $entityId,
                'user_id'     => auth()->check() ? auth()->id() : ( $userCurrentSubscription->user_id ?? null ),
            ]
            : [
                'entity_type' => 'user',
                'user_id'     => $entityId,
            ];

        PaymentHistoryService::store(
            $trxid,
            $paidAmount,
            $payment_method,
            $transition_type,
            '-',
            ( $couponName ),
            $entityId,
            $paymentHistoryContext
        );

        $getcoupon = Coupon::on( 'mysql' )->find( $couponName ?? 0 );

        if ( $getcoupon ) {
            // Referral bonus is based on the actual paid renew amount (after coupon + credit).
            if ( $getcoupon->commission_type == 'flat' ) {
                $commission = (float) convertfloat( (string) $getcoupon->commission );
            } else {
                $commission = round( ( $paidAmount / 100 ) * (float) convertfloat( (string) $getcoupon->commission ), 2 );
            }

            if ( $commission > 0 ) {
                SubscriptionService::creditCouponReferralBonus( $getcoupon, $commission, $trxid, $couponName );
            }
        }

        $userCurrentSubscription->trxid = $trxid;
        SubscriptionService::applyPlanToUserSubscription( $userCurrentSubscription, $getsubscription );

        if ( $getsubscription->id == $usersubscriptionPlan->id ) {
            if ( $userCurrentSubscription->expire_date > now() ) {
                $expiretime = Carbon::parse( $userCurrentSubscription->expire_date )->addMonth( $addMonth );
            } else {
                $expiretime = now()->addMonth( $addMonth );
            }

            $userCurrentSubscription->expire_date = $expiretime;
            $userCurrentSubscription->save();

            return responsejson( 'Renew successfully' );
        }

        $userCurrentSubscription->expire_date = now()->addMonth( $addMonth );
        $userCurrentSubscription->save();

        return responsejson( 'Subscription upgrade successfully!' );
    }
}
