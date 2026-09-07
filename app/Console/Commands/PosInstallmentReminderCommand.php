<?php

namespace App\Console\Commands;

use App\Models\Installment;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VendorInfo;
use App\Notifications\PosInstallmentReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily installment reminders (before / due / overdue) — separate from pos:due-reminder.
 */
class PosInstallmentReminderCommand extends Command
{
    protected $signature = 'pos:installment-reminder';

    protected $description = 'Send POS installment payment reminders to merchant users';

    public function handle(): int
    {
        $sent = 0;

        Tenant::on( 'mysql' )
            ->where( 'type', 'merchant' )
            ->cursor()
            ->each( function ( Tenant $tenant ) use ( &$sent ) {
                try {
                    $tenant->run( function () use ( &$sent ) {
                        $sent += $this->processCurrentTenant();
                    } );
                } catch ( Throwable $e ) {
                    $this->warn( "Tenant {$tenant->id}: " . $e->getMessage() );
                }
            } );

        $this->info( "POS installment reminders sent: {$sent}" );

        return self::SUCCESS;
    }

    private function processCurrentTenant(): int
    {
        $today      = Carbon::today();
        $beforeDays = $this->reminderDaysBefore();
        $sent       = 0;

        $installments = Installment::query()
            ->where( 'remaining_amount', '>', 0 )
            ->where( 'status', '!=', 'paid' )
            ->whereHas( 'posSale', function ( $q ) {
                $q->where( 'payment_status', 'due' )
                    ->where( 'due_amount', '>', 0 );
            } )
            ->with( [
                'posSale:id,barcode,customer_id,vendor_id,user_id,payment_status,due_amount',
                'posSale.customer:id,customer_name',
            ] )
            ->get();

        foreach ( $installments as $installment ) {
            $sale = $installment->posSale;
            if ( ! $sale ) {
                continue;
            }

            $dueDate = Carbon::parse( $installment->due_date )->startOfDay();
            $type    = null;

            if ( $dueDate->lt( $today ) ) {
                $type = 'overdue';
            } elseif ( $dueDate->equalTo( $today ) ) {
                $type = 'due';
            } elseif ( $beforeDays > 0 && $dueDate->equalTo( $today->copy()->addDays( $beforeDays ) ) ) {
                $type = 'before';
            }

            if ( ! $type ) {
                continue;
            }

            if ( optional( $installment->last_reminder_date )->toDateString() === $today->toDateString()
                && (string) $installment->last_reminder_type === $type
            ) {
                continue;
            }

            $user = User::find( $sale->vendor_id ) ?: User::find( $sale->user_id );
            if ( ! $user ) {
                continue;
            }

            $user->notify( new PosInstallmentReminderNotification( $installment, $type ) );

            $installment->last_reminder_date = $today->toDateString();
            $installment->last_reminder_type = $type;
            $installment->save();
            $sent++;
        }

        return $sent;
    }

    private function reminderDaysBefore(): int
    {
        $info = VendorInfo::query()->first();
        if ( $info && $info->due_reminder_days !== null ) {
            return max( 0, (int) $info->due_reminder_days );
        }

        return max( 0, (int) config( 'services.pos_due.reminder_days_before', 1 ) );
    }
}
