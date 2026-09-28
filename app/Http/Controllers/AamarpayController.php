<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesEpsPaymentCallback;
use App\Helper\RedirectHelper;
use App\Models\AdminAdvertise;
use App\Models\CustomerRequiremnt;
use App\Models\DollerRate;
use App\Models\PaymentStore;
use App\Models\ServiceOrder;
use App\Models\ServicePackage;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VendorService;
use App\Notifications\RechargeNotification;
use App\Notifications\SubscriptionNotification;
use App\Services\PaymentHistoryService;
use App\Services\ProductCheckoutService;
use App\Services\SubscriptionRenewService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Notification;

class AamarpayController extends Controller
{
    use HandlesEpsPaymentCallback;

    function servicesuccess()
    {
        $response = $this->verifiedEpsTransaction();

        if ( ! $response ) {
            return redirect( rtrim( config( 'app.redirecturl' ), '/' ) . '/all-service-order?message=Payment verification failed' );
        }
        $vendorservice = ServiceOrder::on( 'mysql' )->where( 'trxid', $response['mer_txnid'] ?? null )->first();

        if ( ! $vendorservice ) {
            return redirect( rtrim( config( 'app.redirecturl' ), '/' ) . '/all-service-order?message=Payment not found' );
        }

        $vendorservice->update([
            'is_paid' => 1
        ]);
        PaymentHistoryService::store($vendorservice->trxid, $vendorservice->amount, 'Aamarpay', 'Service', '-', '', $vendorservice->user_id);

        if ( ! empty( $vendorservice->tenant_id ) ) {
            $url = RedirectHelper::getTenantRedirectUrl( $vendorservice->tenant_id )
                . 'all-service-order?message=' . urlencode( 'Service purchase successfully' );

            return redirect( $url );
        }

        $user = User::find($vendorservice->user_id);
        $path = paymentredirect($user->role_as);
        $url = config('app.redirecturl') . $path . '?message=Service purchase successfully';
        return redirect($url);
    }

    function productcheckoutsuccess()
    {
        // Product checkout belongs to tenant storefronts. Prefer tenant completion
        // and always redirect back to the tenant frontend — never the admin panel.
        $response = $this->verifiedEpsTransaction();

        if ( ! $response ) {
            return redirect( config( 'app.redirecturl' ) . '?message=Payment verification failed' );
        }

        $data = \App\Models\PaymentStore::on( 'mysql' )->where( 'trxid', $response['mer_txnid'] )->first();

        if ( ! $data ) {
            return redirect( config( 'app.redirecturl' ) . '?message=Payment not found' );
        }

        try {
            $url = app( \App\Services\EpsPaymentCompletionService::class )
                ->completeByTransactionId( $response['mer_txnid'], 'checkout' );
        } catch ( \Throwable $e ) {
            $info = is_array( $data->info ) ? $data->info : [];
            $tenantId = $info['storefront_tenant_id']
                ?? $info['placing_tenant_id']
                ?? $info['tenant_id']
                ?? null;
            $base = \App\Helper\RedirectHelper::getPaymentRedirectUrl(
                $tenantId,
                $info['return_url'] ?? null
            );

            return redirect( rtrim( $base, '/' ) . '/?message=' . urlencode( $e->getMessage() ) );
        }

        return redirect( $url );
    }

    function renewsuccess()
    {
        $response = $this->verifiedEpsTransaction();

        if ( ! $response ) {
            return redirect( config( 'app.redirecturl' ) . '?message=Payment verification failed' );
        }
        $data = PaymentStore::on( 'mysql' )->where( ['trxid' => $response['mer_txnid'], 'status' => 'pending'] )->first();

        if ( ! $data ) {
            return redirect( config( 'app.redirecturl' ) . '?message=Payment not found' );
        }

        $user = User::find( $data['info']['user_id'] );
        $subscriptionid = $data['info']['package_id'];
        $trxid = $data->trxid;
        $payment_method = 'Aamarpay';
        $transition_type = 'renew';
        SubscriptionRenewService::subscriptionadd( $user, $subscriptionid, $trxid, $payment_method, $transition_type, $response['amount_original'], $data['info']['coupon'] ?? '' );

        $data->update( ['status' => 'completed'] );

        $path = paymentredirect( $user->role_as );
        $url = config( 'app.redirecturl' ) . $path . '?message=Renew successfull';
        return redirect( $url );
    }
    function advertisesuccess()
    {
        $response = $this->verifiedEpsTransaction();

        if ( ! $response ) {
            return redirect( config( 'app.redirecturl' ) . '?message=Payment verification failed' );
        }
        $adminAdvertise = AdminAdvertise::where('trxid', $response['mer_txnid'])->first();

        $adminAdvertise->update([
            'is_paid' => 1
        ]);
        $dollerRate  =  DollerRate::first()?->amount;
        // if($response['opt_b'] == 'user'){
            PaymentHistoryService::store($adminAdvertise->trxid, ($adminAdvertise->budget_amount * $dollerRate), 'Aamarpay', 'Advertise', '-', '', $adminAdvertise->user_id);
        // }else{
        //     PaymentHistoryService::store($adminAdvertise->trxid, ($adminAdvertise->budget_amount * $dollerRate), 'Ammarpay', 'Advertise', '-', '', tenant()->id);
        // }
        $user = User::find($adminAdvertise->user_id);
        $path = paymentredirect($user->role_as);
        $url = config('app.redirecturl') . $path . '?message=Advertise payment successfull';
        return redirect($url);
    }

    function subscriptionsuccess()
    {
        $response = $this->verifiedEpsTransaction();

        if ( ! $response ) {
            return redirect( config( 'app.redirecturl' ) . '?message=Payment verification failed' );
        }

        try {
            $url = app( \App\Services\EpsPaymentCompletionService::class )
                ->completeByTransactionId( $response['mer_txnid'], 'subscription' );
        } catch ( \Throwable $e ) {
            return redirect( config( 'app.redirecturl' ) . '?message=' . urlencode( $e->getMessage() ) );
        }

        return redirect( $url );
    }

    function rechargesuccess()
    {
        $response = $this->verifiedEpsTransaction();

        if ( ! $response ) {
            return redirect( config( 'app.redirecturl' ) . '?message=Payment verification failed' );
        }

        try {
            $url = app( \App\Services\EpsPaymentCompletionService::class )
                ->completeByTransactionId( $response['mer_txnid'], 'recharge' );
        } catch ( \Throwable $e ) {
            return redirect( config( 'app.redirecturl' ) . '?message=' . urlencode( $e->getMessage() ) );
        }

        return redirect( $url );
    }



    function fail()
    {
        return redirect(config('app.maindomain'));
    }

    function cancel()
    {
        return redirect(config('app.maindomain'));
    }
}
