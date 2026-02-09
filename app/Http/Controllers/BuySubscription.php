<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuysubscriptionRequest;
use App\Http\Requests\CouponApplyRequest;
use App\Models\Coupon as ModelsCoupon;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\SosService;
use App\Services\SubscriptionDueService;
use App\Services\SubscriptionService;
use App\Helper\RedirectHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BuySubscription extends Controller {
    function buy( int $id ) {
        $subscription = Subscription::on('mysql')->findOr( $id, function () {
            return responsejson( 'Not found', 404 );
        } );

        if ( function_exists( 'tenant' ) && tenant() ) {
            $entityId = tenant()->id;
        } else {
            $entityId = auth()->id();
        }

        $proviousdue       = SubscriptionDueService::on('mysql')->subscriptiondue( $entityId );
        $membership_credit = SubscriptionDueService::on('mysql')->membership_credit( $entityId, $subscription->id );

        return response()->json(
            [
                'data'            => 'success',
                'message'         => $subscription,
                'previous_due'    => $proviousdue,
                'previous_credit' => $membership_credit,
            ]
        );
    }

    function coupon( CouponApplyRequest $request ) {
        $validateData = $request->validated();
        $query = ModelsCoupon::on( 'mysql' )->where( 'name', $validateData['name'] )->where( 'user_id', '!=', Auth::id() );

        if ( function_exists( 'tenant' ) && tenant() ) {
            $query->where( 'tenant_id', '!=', tenant()->id );
        }

        $coupon = $query->select( 'id', 'amount', 'type' )->first();

        return $this->response( $coupon );
    }

    function buysubscription( BuysubscriptionRequest $request ) {
        $validateData = $request->validated();

        // if ( function_exists( 'tenant' ) && tenant() ) {
            $entity = tenant();
            $id     = $entity->id;
        // } else {
        //     $entity = User::on('mysql')->find( vendorId() );
        //     $id     = $entity->id;
        // }

        if ( $entity instanceof User ) {
            $hasSubscription = $entity->usersubscription;
        } else {
            $hasSubscription = UserSubscription::on('mysql')->where( 'tenant_id', $id )->first();
        }

        // if ( $hasSubscription ) {
        //     return responsejson( 'You have a subscription. You can not buy again.', 'fail' );
        // }

        $subscription = Subscription::on('mysql')->find( $validateData['subscription_id'] );
        $amount       = $subscription->subscription_amount;

        $coupon = null;
        if ( request( 'coupon_id' ) != '' ) {
            $couponUsed = ModelsCoupon::on( 'mysql' )->withCount( 'couponused' )->find( request( 'coupon_id' ) );

            $coupon = ModelsCoupon::on( 'mysql' )
                ->where( 'id', request( 'coupon_id' ) )
                ->where( 'status', 'active' )
                ->where( 'limitation', '>', $couponUsed->couponused_count )
                ->whereDate( 'expire_date', '>=', now() )
                ->where( 'user_id', '!=', Auth::id() );

            if ( function_exists( 'tenant' ) && tenant() ) {
                $coupon->where( 'tenant_id', '!=', tenant()->id );
            }

            $coupon = $coupon->first();

            if ( !$coupon ) {
                return responsejson( 'Coupon not available', 'fail' );
            }

            if ( $coupon->type == 'flat' ) {
                $amount = ( $amount - $coupon->amount );
            } else {
                $amount = ( $amount - ( ( $amount / 100 ) * $coupon->amount ) );
            }
        }

        if ( $validateData['payment_type'] == 'aamarpay' ) {
            return SosService::aamarpaysubscription( $amount, $validateData, $coupon?->id );
        } else {
            $balance = $entity->balance;

            if ( $balance >= $amount ) {

                if ( request( 'payment_type' ) == 'free' ) {
                    $paymentmethod = "free";
                } elseif ( request( 'payment_type' ) == 'my-wallet' ) {
                    $paymentmethod = "My wallet";
                }
                $data = SubscriptionService::store( $subscription, $entity, $amount, $coupon?->id, $paymentmethod );

                $entity->balance = ( $entity->balance - $amount );
                $entity->save();

                if ( $data == '2' || $data == '3' ) {
                    $path = paymentredirect( $data );
                    return RedirectHelper::getRedirectUrl() . $path . '?message=successful';
                }

                return $data;
            } else {
                return responsejson( 'Not enough balance', 'fail' );
            }
        }
    }
}
