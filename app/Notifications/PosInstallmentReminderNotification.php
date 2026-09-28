<?php

namespace App\Notifications;

use App\Models\Installment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PosInstallmentReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Installment $installment,
        public string $reminderType
    ) {
    }

    public function via( $notifiable ): array
    {
        return ['database'];
    }

    public function toArray( $notifiable ): array
    {
        $this->installment->loadMissing( [
            'posSale:id,barcode,customer_id,vendor_id',
            'posSale.customer:id,customer_name',
        ] );

        $sale     = $this->installment->posSale;
        $customer = $sale?->customer;
        $customerName = $customer?->customer_name ?? 'Customer';
        $invoice  = $sale?->barcode ?? ( '#' . ( $sale?->id ?? '' ) );
        $totalCount = Installment::where( 'pos_sales_id', $this->installment->pos_sales_id )->count();
        $number   = $this->installment->installment_number;
        $amount   = number_format( (float) $this->installment->amount, 2 );
        $remaining = number_format( (float) $this->installment->remaining_amount, 2 );
        $dueDate  = optional( $this->installment->due_date )->format( 'd F Y' ) ?? 'N/A';

        $label = match ( $this->reminderType ) {
            'before'  => 'Upcoming Installment Reminder',
            'due'     => 'Installment Due Today',
            'overdue' => 'Overdue Installment Reminder',
            default   => 'Installment Payment Reminder',
        };

        $text = "{$label}\n\nInstallment Payment Reminder\nCustomer: {$customerName}\nOrder: {$invoice}\nInstallment: {$number} of {$totalCount}\nAmount: ৳{$amount}\nRemaining: ৳{$remaining}\nDue Date: {$dueDate}";

        return [
            'user_id'            => $notifiable->id ?? null,
            'text'               => $text,
            'type'               => 'pos_installment_reminder',
            'reminder_type'      => $this->reminderType,
            'pos_sales_id'       => $sale?->id,
            'installment_id'     => $this->installment->id,
            'installment_number' => $number,
            'installment_count'  => $totalCount,
            'customer_id'        => $sale?->customer_id,
            'invoice'            => $invoice,
            'amount'             => (float) $this->installment->amount,
            'remaining_amount'   => (float) $this->installment->remaining_amount,
            'due_date'           => optional( $this->installment->due_date )->format( 'Y-m-d' ),
            'redirect'           => '/pos-sales/installments/' . ( $sale?->id ?? '' ),
        ];
    }
}
