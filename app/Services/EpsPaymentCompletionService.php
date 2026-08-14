<?php

namespace App\Services;

use App\Helper\RedirectHelper;
use App\Models\PaymentStore;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\RechargeNotification;
use App\Notifications\SubscriptionNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

class EpsPaymentCompletionService
{
    /**
     * Complete a pending PaymentStore row after EPS success.
     * Returns a frontend redirect URL.
     */
    public function completeByTransactionId( string $merchantTransactionId, ?string $hint = null ): string
    {
        $verification = EpsPaymentService::verifyTransaction( $merchantTransactionId );

        if ( ! EpsPaymentService::isSuccessful( $verification ) ) {
            throw new RuntimeException( 'EPS transaction is not successful.' );
        }

        $payment = PaymentStore::on( 'mysql' )
            ->where( 'trxid', $merchantTransactionId )
            ->first();

        if ( ! $payment ) {
            throw new RuntimeException( 'Payment record not found for transaction: ' . $merchantTransactionId );
        }

        if ( ( $payment->status ?? null ) === 'completed' ) {
            return $this->redirectForPayment( $payment, 'Payment already completed' );
        }

        $type = $hint ?: (string) ( $payment->payment_type ?? '' );

        return match ( true ) {
            in_array( $type, ['recharge', 'recharge-success', 'recharge-success-for-us'], true ) => $this->completeRecharge( $payment, $verification ),
            in_array( $type, ['subscription', 'subscription-success'], true ) => $this->completeSubscription( $payment, $verification ),
            in_array( $type, ['renew', 'renew-success'], true ) => $this->completeRenew( $payment, $verification ),
            default => throw new RuntimeException( 'Unsupported payment type: ' . ( $type ?: 'unknown' ) ),
        };
    }

    /**
     * Credit any successful pending EPS payments for this tenant.
     * Prefer MerchantTransactionId from the current request or Referer
     * (EPS appends it to the dashboard return URL).
     */
    public function completePendingForTenant( ?string $tenantId, int $hours = 12 ): int
    {
        $completed = 0;
        $trxFromBrowser = $this->resolveIncomingTransactionId();

        if ( $trxFromBrowser ) {
            try {
                $hint = request( 'eps_callback' )
                    ?: request( 'callback' )
                    ?: $this->callbackHintFromReferer();

                $this->completeByTransactionId( (string) $trxFromBrowser, $hint );
                $completed++;
            } catch ( Throwable $e ) {
                Log::warning( 'EPS referer/request complete failed', [
                    'trx'    => $trxFromBrowser,
                    'tenant' => $tenantId,
                    'error'  => $e->getMessage(),
                ] );
            }
        }

        if ( ! $tenantId ) {
            return $completed;
        }

        $cacheKey = 'eps-pending-tenant-' . $tenantId;
        if ( cache()->get( $cacheKey ) ) {
            return $completed;
        }
        cache()->put( $cacheKey, 1, now()->addSeconds( 15 ) );

        $payments = PaymentStore::on( 'mysql' )
            ->where( 'status', 'pending' )
            ->whereIn( 'payment_type', [
                'recharge',
                'subscription',
                'renew',
                'recharge-success',
                'recharge-success-for-us',
                'subscription-success',
                'renew-success',
            ] )
            ->where( 'created_at', '>=', now()->subHours( max( 1, $hours ) ) )
            ->orderBy( 'id' )
            ->limit( 20 )
            ->get()
            ->filter( function ( PaymentStore $payment ) use ( $tenantId ) {
                $info = is_array( $payment->info ) ? $payment->info : [];

                return (string) ( $info['tenant_id'] ?? '' ) === (string) $tenantId;
            } );

        foreach ( $payments as $payment ) {
            if ( $trxFromBrowser && (string) $payment->trxid === (string) $trxFromBrowser ) {
                continue;
            }

            try {
                $verification = EpsPaymentService::verifyTransaction( (string) $payment->trxid );
                if ( ! EpsPaymentService::isSuccessful( $verification ) ) {
                    continue;
                }

                $this->completeByTransactionId(
                    (string) $payment->trxid,
                    (string) ( $payment->payment_type ?? 'recharge' )
                );
                $completed++;
            } catch ( Throwable $e ) {
                Log::warning( 'EPS pending tenant complete skipped', [
                    'trx'    => $payment->trxid,
                    'tenant' => $tenantId,
                    'error'  => $e->getMessage(),
                ] );
            }
        }

        return $completed;
    }

    private function resolveIncomingTransactionId(): ?string
    {
        $direct = EpsPaymentService::resolveTransactionId()
            ?: request( 'merchant_transaction_id' );

        if ( $direct ) {
            return (string) $direct;
        }

        $referer = (string) ( request()->headers->get( 'Referer' )
            ?: request()->headers->get( 'Referrer' )
            ?: '' );

        if ( $referer === '' ) {
            return null;
        }

        $query = parse_url( $referer, PHP_URL_QUERY );
        if ( ! is_string( $query ) || $query === '' ) {
            return null;
        }

        parse_str( $query, $params );

        return $params['MerchantTransactionId']
            ?? $params['merchantTransactionId']
            ?? $params['mer_txnid']
            ?? null;
    }

    private function callbackHintFromReferer(): ?string
    {
        $referer = (string) ( request()->headers->get( 'Referer' )
            ?: request()->headers->get( 'Referrer' )
            ?: '' );

        if ( $referer === '' ) {
            return null;
        }

        $query = parse_url( $referer, PHP_URL_QUERY );
        if ( ! is_string( $query ) || $query === '' ) {
            return null;
        }

        parse_str( $query, $params );

        return $params['eps_callback'] ?? $params['callback'] ?? null;
    }

    private function completeRecharge( PaymentStore $payment, array $verification ): string
    {
        $info   = is_array( $payment->info ) ? $payment->info : [];
        $amount = (float) ( $info['amount'] ?? $verification['TotalAmount'] ?? $verification['StoreAmount'] ?? 0 );

        if ( $amount <= 0 ) {
            throw new RuntimeException( 'Invalid recharge amount.' );
        }

        $tenantId = $info['tenant_id'] ?? null;
        $userId   = $info['user_id'] ?? null;

        if ( $tenantId ) {
            $tenant = Tenant::on( 'mysql' )->find( $tenantId );
            if ( ! $tenant ) {
                throw new RuntimeException( 'Tenant not found for recharge.' );
            }

            $tenant->increment( 'balance', $amount );

            PaymentHistoryService::store(
                $payment->trxid,
                $amount,
                'Aamarpay',
                'Recharge',
                '+',
                '',
                $tenantId,
                [
                    'entity_type' => 'tenant',
                    'tenant_id'   => $tenantId,
                    'user_id'     => $userId,
                ]
            );

            try {
                Notification::send( $tenant, new RechargeNotification( $tenant, $amount, $payment->trxid ) );
            } catch ( Throwable $e ) {
                Log::warning( 'Recharge notification failed', ['error' => $e->getMessage()] );
            }

            $payment->update( ['status' => 'completed'] );

            return RedirectHelper::getPaymentRedirectUrl( $tenantId, $info['return_url'] ?? null )
                . 'dashboard?message=' . urlencode( 'Recharge successful' );
        }

        if ( $userId ) {
            $user = User::on( 'mysql' )->find( $userId );
            if ( ! $user ) {
                throw new RuntimeException( 'User not found for recharge.' );
            }

            $user->increment( 'balance', $amount );

            PaymentHistoryService::store(
                $payment->trxid,
                $amount,
                'Aamarpay',
                'Recharge',
                '+',
                '',
                $userId
            );

            try {
                Notification::send( $user, new RechargeNotification( $user, $amount, $payment->trxid ) );
            } catch ( Throwable $e ) {
                Log::warning( 'Recharge notification failed', ['error' => $e->getMessage()] );
            }

            $payment->update( ['status' => 'completed'] );

            $path = paymentredirect( $user->role_as );

            return rtrim( (string) config( 'app.redirecturl' ), '/' ) . '/' . ltrim( $path, '/' )
                . '?message=' . urlencode( 'Recharge successful' );
        }

        throw new RuntimeException( 'Recharge payment is missing tenant_id/user_id.' );
    }

    private function completeSubscription( PaymentStore $payment, array $verification ): string
    {
        $info         = is_array( $payment->info ) ? $payment->info : [];
        $subscription = Subscription::on( 'mysql' )->find( $info['subscription_id'] ?? null );
        $couponId     = $info['coupon_id'] ?? null;

        $entity = null;
        if ( ! empty( $info['tenant_id'] ) ) {
            $entity = Tenant::on( 'mysql' )->find( $info['tenant_id'] );
        }
        if ( ! $entity && ! empty( $info['user_id'] ) ) {
            $entity = User::on( 'mysql' )->find( $info['user_id'] );
        }

        if ( ! $subscription || ! $entity ) {
            throw new RuntimeException( 'Subscription payment could not be completed.' );
        }

        $amount = $verification['TotalAmount'] ?? $verification['StoreAmount'] ?? $subscription->subscription_amount;

        $result = SubscriptionService::store(
            $subscription,
            $entity,
            $amount,
            $couponId,
            'Aamarpay',
            $info['user_id'] ?? null
        );

        $payment->update( ['status' => 'completed'] );

        if ( $entity instanceof User && ! is_object( $result ) && ( $result == '2' || $result == 3 ) ) {
            foreach ( $entity->tokens as $token ) {
                $token->delete();
            }

            return rtrim( (string) config( 'app.maindomain' ), '/' ) . '/';
        }

        if ( $entity instanceof User ) {
            try {
                Notification::send( $entity, new SubscriptionNotification( $entity, 'Congratulations! Your package was successfully purchased!' ) );
                $admin = User::on( 'mysql' )->where( 'role_as', 1 )->first();
                if ( $admin ) {
                    Notification::send( $admin, new SubscriptionNotification( $admin, $entity->email . ' Purchase a new package' ) );
                }
            } catch ( Throwable $e ) {
                Log::warning( 'Subscription notification failed', ['error' => $e->getMessage()] );
            }

            $path = paymentredirect( $entity->role_as );

            return rtrim( (string) config( 'app.redirecturl' ), '/' ) . '/' . ltrim( $path, '/' )
                . '?message=' . urlencode( 'Subscription added successfull' );
        }

        return RedirectHelper::getPaymentRedirectUrl( $entity->id, $info['return_url'] ?? null )
            . 'dashboard?message=' . urlencode( 'Subscription added successfull' );
    }

    private function completeRenew( PaymentStore $payment, array $verification ): string
    {
        $info = is_array( $payment->info ) ? $payment->info : [];
        $user = User::on( 'mysql' )->find( $info['user_id'] ?? null );

        if ( ! $user ) {
            // Tenant renew may store tenant context in info
            if ( ! empty( $info['tenant_id'] ) ) {
                $tenant = Tenant::on( 'mysql' )->find( $info['tenant_id'] );
                if ( $tenant ) {
                    $amount = $verification['TotalAmount'] ?? $verification['StoreAmount'] ?? 0;
                    SubscriptionRenewService::subscriptionadd(
                        $tenant,
                        $info['package_id'],
                        $payment->trxid,
                        'Aamarpay',
                        'renew',
                        $amount,
                        $info['coupon'] ?? ''
                    );
                    $payment->update( ['status' => 'completed'] );

                    return RedirectHelper::getPaymentRedirectUrl( $tenant->id, $info['return_url'] ?? null )
                        . 'dashboard?message=' . urlencode( 'Renew successfull' );
                }
            }

            throw new RuntimeException( 'Renew payment user/tenant not found.' );
        }

        $amount = $verification['TotalAmount'] ?? $verification['StoreAmount'] ?? 0;
        SubscriptionRenewService::subscriptionadd(
            $user,
            $info['package_id'],
            $payment->trxid,
            'Aamarpay',
            'renew',
            $amount,
            $info['coupon'] ?? ''
        );

        $payment->update( ['status' => 'completed'] );

        $path = paymentredirect( $user->role_as );

        return rtrim( (string) config( 'app.redirecturl' ), '/' ) . '/' . ltrim( $path, '/' )
            . '?message=' . urlencode( 'Renew successfull' );
    }

    private function redirectForPayment( PaymentStore $payment, string $message ): string
    {
        $info     = is_array( $payment->info ) ? $payment->info : [];
        $tenantId = $info['tenant_id'] ?? null;

        if ( $tenantId ) {
            return RedirectHelper::getPaymentRedirectUrl( $tenantId, $info['return_url'] ?? null )
                . 'dashboard?message=' . urlencode( $message );
        }

        return rtrim( (string) config( 'app.redirecturl' ), '/' ) . '/?message=' . urlencode( $message );
    }
}
