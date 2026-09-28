<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PreOrderCustomerNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Remind authenticated customers when pre-order expected delivery is approaching / due.
 */
class PreOrderDeliveryReminderCommand extends Command
{
    protected $signature = 'preorder:delivery-reminder {--days=1 : Days before expected delivery}';

    protected $description = 'Send pre-order expected-delivery reminders to authenticated customers';

    public function handle(): int
    {
        $sent = 0;
        $days = max( 0, (int) $this->option( 'days' ) );

        Tenant::on( 'mysql' )
            ->where( 'type', 'merchant' )
            ->cursor()
            ->each( function ( Tenant $tenant ) use ( &$sent, $days ) {
                try {
                    $tenant->run( function () use ( &$sent, $days ) {
                        $sent += $this->processCurrentTenant( $days );
                    } );
                } catch ( Throwable $e ) {
                    $this->warn( "Tenant {$tenant->id}: " . $e->getMessage() );
                }
            } );

        $this->info( "Pre-order delivery reminders sent: {$sent}" );

        return self::SUCCESS;
    }

    private function processCurrentTenant( int $daysBefore ): int
    {
        $today    = Carbon::today();
        $target   = $today->copy()->addDays( $daysBefore );
        $sent     = 0;

        $orders = Order::query()
            ->where( 'is_pre_order', true )
            ->whereNotNull( 'expected_delivery_date' )
            ->whereNotNull( 'user_id' )
            ->where( 'user_id', '>', 0 )
            ->whereNotIn( 'status', ['cancel', 'cancelled', 'delivered', 'completed', 'rejected'] )
            ->whereDate( 'expected_delivery_date', $target->toDateString() )
            ->with( 'product:id,name' )
            ->get();

        foreach ( $orders as $order ) {
            if ( optional( $order->pre_order_reminder_date )->toDateString() === $today->toDateString()
                || ( is_string( $order->pre_order_reminder_date ) && $order->pre_order_reminder_date === $today->toDateString() )
            ) {
                continue;
            }

            $user = User::find( $order->user_id );
            if ( ! $user ) {
                continue;
            }

            $user->notify( new PreOrderCustomerNotification( $order, 'delivery_reminder' ) );
            $order->pre_order_reminder_date = $today->toDateString();
            $order->save();
            $sent++;
        }

        return $sent;
    }
}
