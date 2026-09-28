<?php

namespace App\Console\Commands;

use App\Models\PosSaleDue;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VendorInfo;
use App\Notifications\PosDueReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily due reminders for merchant POS partial payments (pos_sale_dues table).
 */
class PosDueReminderCommand extends Command
{
    protected $signature = 'pos:due-reminder';

    protected $description = 'Send POS due payment reminders (before / due / overdue) to merchant users';

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

        $this->info( "POS due reminders sent: {$sent}" );

        return self::SUCCESS;
    }

    private function processCurrentTenant(): int
    {
        $today = Carbon::today();
        $beforeDays = $this->reminderDaysBefore();
        $sent = 0;

        $dues = PosSaleDue::query()
            ->whereNotNull( 'due_date' )
            ->whereHas( 'posSale', function ( $q ) {
                $q->where( 'payment_status', 'due' )
                    ->where( 'due_amount', '>', 0 );
            } )
            ->with( [
                'posSale.customer:id,customer_name',
            ] )
            ->get();

        foreach ( $dues as $due ) {
            $sale = $due->posSale;
            if ( ! $sale ) {
                continue;
            }

            $dueDate = Carbon::parse( $due->due_date )->startOfDay();
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

            if ( optional( $due->last_due_reminder_date )->toDateString() === $today->toDateString()
                && (string) $due->last_due_reminder_type === $type
            ) {
                continue;
            }

            $user = User::find( $sale->vendor_id ) ?: User::find( $sale->user_id );
            if ( ! $user ) {
                continue;
            }

            $user->notify( new PosDueReminderNotification( $sale, $type ) );

            $due->last_due_reminder_date = $today->toDateString();
            $due->last_due_reminder_type = $type;
            $due->save();
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
