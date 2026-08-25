<?php

namespace App\Http\Controllers;

use App\Http\Requests\BuysubscriptionRequest;
use App\Http\Requests\CouponApplyRequest;
use App\Models\Coupon as ModelsCoupon;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\SosService;
use App\Services\SubscriptionDueService;
use App\Services\SubscriptionService;
use App\Helper\RedirectHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        $proviousdue       = SubscriptionDueService::subscriptiondue( $entityId );
        $membership_credit = SubscriptionDueService::membership_credit( $entityId, $subscription->id );

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
            $hasSubscription = UserSubscription::on('mysql')->where( 'tenant_id', tenant()->id )->first();
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

            // Full cover (e.g. 100% off) is valid — payable becomes 0.
            if ( $amount < 0 ) {
                $amount = 0;
            }
        }

        // 100% / full coupon — activate membership without wallet or gateway charge.
        if ( (float) $amount <= 0 ) {
            $paymentmethod = $coupon ? 'Coupon' : 'free';
            $data          = SubscriptionService::store( $subscription, $entity, 0, $coupon?->id, $paymentmethod );

            if ( $data == '2' || $data == '3' ) {
                $path = paymentredirect( $data );
                return RedirectHelper::getRedirectUrl() . $path . '?message=successful';
            }

            return $data;
        }

        if ( $validateData['payment_type'] == 'aamarpay' ) {
            return SosService::aamarpaysubscription( $amount, $validateData, $coupon?->id );
        }

        $amount = (float) convertfloat( (string) $amount );

        if ( $entity instanceof Tenant ) {
            $result = DB::connection( 'mysql' )->transaction( function () use ( $entity, $amount, $subscription, $coupon ) {
                $row = DB::connection( 'mysql' )
                    ->table( 'tenants' )
                    ->where( 'id', $entity->id )
                    ->lockForUpdate()
                    ->first( ['id', 'balance'] );

                if ( ! $row ) {
                    return responsejson( 'Tenant wallet not found.', 'fail' );
                }

                $balance = (float) convertfloat( (string) ( $row->balance ?? 0 ) );
                if ( $balance < $amount ) {
                    return responsejson( 'Not enough balance', 'fail' );
                }

                DB::connection( 'mysql' )
                    ->table( 'tenants' )
                    ->where( 'id', $entity->id )
                    ->update( [
                        'balance'    => $balance - $amount,
                        'updated_at' => now(),
                    ] );

                $entity->setAttribute( 'balance', $balance - $amount );

                return SubscriptionService::store(
                    $subscription,
                    Tenant::on( 'mysql' )->find( $entity->id ),
                    $amount,
                    $coupon?->id,
                    'My wallet'
                );
            } );

            if ( $result instanceof \Illuminate\Http\JsonResponse ) {
                return $result;
            }

            if ( $result == '2' || $result == '3' ) {
                $path = paymentredirect( $result );
                return RedirectHelper::getRedirectUrl() . $path . '?message=successful';
            }

            return $result;
        }

        $balance = (float) convertfloat( (string) ( $entity->balance ?? 0 ) );
        if ( $balance < $amount ) {
            return responsejson( 'Not enough balance', 'fail' );
        }

        $entity->balance = $balance - $amount;
        $entity->save();

        $data = SubscriptionService::store( $subscription, $entity, $amount, $coupon?->id, 'My wallet' );

        if ( $data == '2' || $data == '3' ) {
            $path = paymentredirect( $data );
            return RedirectHelper::getRedirectUrl() . $path . '?message=successful';
        }

        return $data;
    }
}
