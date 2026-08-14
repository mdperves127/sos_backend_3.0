<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Concerns\HandlesEpsPaymentCallback;
use App\Http\Controllers\Controller;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\PaymentStore;
use App\Models\AdminAdvertise;
use App\Models\Subscription;
use App\Services\PaymentHistoryService;
use App\Services\SubscriptionRenewService;
use App\Services\SubscriptionService;
use App\Services\ProductCheckoutService;
use App\Services\EpsPaymentCompletionService;
use Illuminate\Support\Facades\Notification;
use App\Notifications\RechargeNotification;
use App\Notifications\SubscriptionNotification;
use App\Models\Tenant;
use App\Models\DollerRate;
use App\Helper\RedirectHelper;

class AamarpayController extends Controller
{
    use HandlesEpsPaymentCallback;

    private function frontendBase( ?array $paymentInfo = null ): string
    {
        $tenantId = tenant()?->id ?? ( $paymentInfo['tenant_id'] ?? null );

        return RedirectHelper::getPaymentRedirectUrl( $tenantId, $paymentInfo['return_url'] ?? null );
    }

    function servicesuccess()
    {
        $response = $this->verifiedEpsTransaction();

        if ( ! $response ) {
            return redirect( $this->frontendBase() . 'all-service-order?message=Payment verification failed' );
        }
        $vendorservice = ServiceOrder::on( 'mysql' )->where( 'trxid', $response['mer_txnid'] ?? null )->first();

        if ( ! $vendorservice ) {
            return redirect( $this->frontendBase() . 'all-service-order?message=Payment not found' );
        }

        $vendorservice->update( [
            'is_paid' => 1,
        ] );
        PaymentHistoryService::store(
            $vendorservice->trxid,
            $vendorservice->amount,
            'Aamarpay',
            'Service',
            '-',
            '',
            $vendorservice->tenant_id ?: $vendorservice->user_id,
            [
                'entity_type' => 'tenant',
                'tenant_id'   => $vendorservice->tenant_id,
                'user_id'     => $vendorservice->user_id,
            ]
        );

        $redirectBase = RedirectHelper::getTenantRedirectUrl( $vendorservice->tenant_id );
        $url          = $redirectBase . 'all-service-order?message=' . urlencode( 'Service purchase successfully' );

        return redirect( $url );
    }

    function productcheckoutsuccess()
    {
        $response = $this->verifiedEpsTransaction();

        if ( ! $response ) {
            return redirect( $this->frontendBase() . '?message=Payment verification failed' );
        }
        $data = PaymentStore::where( 'trxid', $response['mer_txnid'] )->first();

        if ( ! $data ) {
            return redirect( $this->frontendBase() . '?message=Payment not found' );
        }
        $info = $data->info;

        ProductCheckoutService::store(
            $info['cartid'],
            $info['productid'],
            $info['totalqty'],
            $info['userid'],
            $info['datas'],
            'aamarpay',
            $info['tenant_id'] ?? null,
            $info['placing_tenant_id'] ?? null,
            $info['order_media'] ?? $data->order_media ?? null
        );

        $data->update( ['status' => 'completed', 'last_status' => 'completed'] );

        $user = User::find( $info['userid'] );
        $path = paymentredirect( $user->role_as );
        $url  = $this->frontendBase( $info ) . $path . '?message=Product purchase successfully';

        return redirect( $url );
    }

    function renewsuccess()
    {
        $response = $this->verifiedEpsTransaction();

        if ( ! $response ) {
            return redirect( $this->frontendBase() . 'dashboard?message=Payment verification failed' );
        }

        try {
            $url = app( EpsPaymentCompletionService::class )
                ->completeByTransactionId( $response['mer_txnid'], 'renew' );
        } catch ( \Throwable $e ) {
            return redirect( $this->frontendBase() . 'dashboard?message=' . urlencode( $e->getMessage() ) );
        }

        return redirect( $url );
    }

    function advertisesuccess()
    {
        $response = $this->verifiedEpsTransaction();

        if ( ! $response ) {
            return redirect( $this->frontendBase() . '?message=Payment verification failed' );
        }
        $adminAdvertise = AdminAdvertise::on( 'mysql' )->where( 'trxid', $response['mer_txnid'] )->first();

        $adminAdvertise->update( [
            'is_paid' => 1,
        ] );
        $dollerRate = DollerRate::first()?->amount;

        PaymentHistoryService::store(
            $adminAdvertise->trxid,
            ( $adminAdvertise->budget_amount * $dollerRate ),
            'Aamarpay',
            'Advertise',
            '-',
            '',
            $adminAdvertise->tenant_id ?: $adminAdvertise->user_id,
            $adminAdvertise->tenant_id
                ? [
                    'entity_type' => 'tenant',
                    'tenant_id'   => $adminAdvertise->tenant_id,
                    'user_id'     => $adminAdvertise->user_id,
                ]
                : [
                    'entity_type' => 'user',
                    'user_id'     => $adminAdvertise->user_id,
                ]
        );
        $user = User::find( $adminAdvertise->user_id );
        $path = paymentredirect( $user->role_as );
        $url  = $this->frontendBase() . $path . '?message=Advertise payment successfull';

        return redirect( $url );
    }

    function subscriptionsuccess()
    {
        $response = $this->verifiedEpsTransaction();

        if ( ! $response ) {
            return redirect( $this->frontendBase() . 'dashboard?message=Payment verification failed' );
        }

        try {
            $url = app( EpsPaymentCompletionService::class )
                ->completeByTransactionId( $response['mer_txnid'], 'subscription' );
        } catch ( \Throwable $e ) {
            return redirect( $this->frontendBase() . 'dashboard?message=' . urlencode( $e->getMessage() ) );
        }

        return redirect( $url );
    }

    function rechargesuccess()
    {
        $response = $this->verifiedEpsTransaction();

        if ( ! $response ) {
            return redirect( $this->frontendBase() . 'dashboard?message=Payment verification failed' );
        }

        try {
            $url = app( EpsPaymentCompletionService::class )
                ->completeByTransactionId( $response['mer_txnid'], 'recharge' );
        } catch ( \Throwable $e ) {
            return redirect( $this->frontendBase() . 'dashboard?message=' . urlencode( $e->getMessage() ) );
        }

        return redirect( $url );
    }

    function fail()
    {
        return redirect( RedirectHelper::getTenantRedirectUrl( tenant()?->id ) . 'dashboard?message=Payment failed' );
    }

    function cancel()
    {
        return redirect( RedirectHelper::getTenantRedirectUrl( tenant()?->id ) . 'dashboard?message=Payment cancelled' );
    }
}
