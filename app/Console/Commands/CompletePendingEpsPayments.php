<?php

namespace App\Console\Commands;

use App\Models\PaymentStore;
use App\Services\EpsPaymentCompletionService;
use App\Services\EpsPaymentService;
use Illuminate\Console\Command;
use Throwable;

class CompletePendingEpsPayments extends Command
{
    protected $signature = 'eps:complete-pending
                            {trxid? : Optional merchant transaction id to complete}
                            {--hours=48 : Only scan pending rows newer than this many hours}';

    protected $description = 'Verify pending EPS (aamarpay) payments and credit successful ones';

    public function handle( EpsPaymentCompletionService $completion ): int
    {
        $trxid = $this->argument( 'trxid' );

        $query = PaymentStore::on( 'mysql' )
            ->where( 'status', 'pending' )
            ->where( function ( $q ) {
                $q->where( 'payment_gateway', 'aamarpay' )
                    ->orWhereNull( 'payment_gateway' );
            } )
            ->whereIn( 'payment_type', [
                'recharge',
                'subscription',
                'renew',
                'recharge-success',
                'recharge-success-for-us',
                'subscription-success',
                'renew-success',
            ] );

        if ( $trxid ) {
            $query->where( 'trxid', $trxid );
        } else {
            $hours = max( 1, (int) $this->option( 'hours' ) );
            $query->where( 'created_at', '>=', now()->subHours( $hours ) );
        }

        $payments = $query->orderBy( 'id' )->get();

        if ( $payments->isEmpty() ) {
            $this->info( 'No pending EPS payments found.' );

            return self::SUCCESS;
        }

        $ok = 0;
        $skip = 0;
        $fail = 0;

        foreach ( $payments as $payment ) {
            try {
                $verification = EpsPaymentService::verifyTransaction( (string) $payment->trxid );

                if ( ! EpsPaymentService::isSuccessful( $verification ) ) {
                    $this->line( "skip {$payment->trxid}: not successful at EPS yet" );
                    $skip++;
                    continue;
                }

                $url = $completion->completeByTransactionId(
                    (string) $payment->trxid,
                    (string) ( $payment->payment_type ?? 'recharge' )
                );

                $this->info( "completed {$payment->trxid} → {$url}" );
                $ok++;
            } catch ( Throwable $e ) {
                $this->error( "fail {$payment->trxid}: {$e->getMessage()}" );
                $fail++;
            }
        }

        $this->info( "Done. completed={$ok} skipped={$skip} failed={$fail}" );

        return $fail > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
    }
}
