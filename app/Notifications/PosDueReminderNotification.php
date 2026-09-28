<?php

namespace App\Notifications;

use App\Models\PosSales;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PosDueReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public PosSales $sale,
        public string $reminderType
    ) {
    }

    public function via( $notifiable ): array
    {
        return ['database'];
    }

    public function toArray( $notifiable ): array
    {
        $this->sale->loadMissing( ['customer:id,customer_name', 'dueRecord'] );

        $customer = $this->sale->customer;
        $customerName = $customer?->customer_name ?? 'Customer';
        $invoice = $this->sale->barcode ?? ( '#' . $this->sale->id );
        $due = number_format( (float) ( $this->sale->due_amount ?? 0 ), 2 );
        $dueDateValue = $this->sale->dueRecord?->due_date;
        $dueDate = $dueDateValue
            ? ( is_string( $dueDateValue ) ? $dueDateValue : $dueDateValue->format( 'Y-m-d' ) )
            : 'N/A';

        $label = match ( $this->reminderType ) {
            'before'  => 'Upcoming Due Payment Reminder',
            'due'     => 'Payment Due Today',
            'overdue' => 'Overdue Payment Reminder',
            default   => 'Due Payment Reminder',
        };

        $text = "{$label}\nCustomer: {$customerName}\nOrder: {$invoice}\nRemaining Due: ৳{$due}\nDue Date: {$dueDate}";

        return [
            'user_id'       => $notifiable->id ?? null,
            'text'          => $text,
            'type'          => 'pos_due_reminder',
            'reminder_type' => $this->reminderType,
            'pos_sales_id'  => $this->sale->id,
            'customer_id'   => $this->sale->customer_id,
            'invoice'       => $invoice,
            'due_amount'    => (float) ( $this->sale->due_amount ?? 0 ),
            'due_date'      => $dueDate,
            'redirect'      => '/pos-sales/show/' . $this->sale->id,
        ];
    }
}
