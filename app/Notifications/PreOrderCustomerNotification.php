<?php

namespace App\Notifications;

use App\Models\Order;
use App\Services\ProductPreOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PreOrderCustomerNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Order $order,
        public string $event
    ) {
    }

    public function via( $notifiable ): array
    {
        return ['database'];
    }

    public function toArray( $notifiable ): array
    {
        $this->order->loadMissing( ['product:id,name'] );

        $productName = $this->order->product?->name ?? 'Product';
        $invoice     = $this->order->order_id ?? ( '#' . $this->order->id );
        $dueDate     = $this->order->expected_delivery_date
            ? ( is_string( $this->order->expected_delivery_date )
                ? $this->order->expected_delivery_date
                : $this->order->expected_delivery_date->format( 'd F Y' ) )
            : 'N/A';
        $lifecycle = ProductPreOrderService::lifecycleFromOrderStatus( (string) $this->order->status );
        $due       = number_format( (float) ( $this->order->due_amount ?? 0 ), 2 );
        $paid      = number_format( (float) ( $this->order->paid_amount ?? $this->order->totaladvancepayment ?? 0 ), 2 );

        $label = match ( $this->event ) {
            'placed'           => 'Pre-order successfully placed',
            'payment_received' => 'Pre-order payment received',
            'confirmed'        => 'Pre-order confirmed',
            'status_changed'   => 'Pre-order status updated',
            'ready'            => 'Your pre-order is ready',
            'cancelled'        => 'Pre-order cancelled',
            'delivery_reminder'=> 'Pre-order delivery reminder',
            default            => 'Pre-order update',
        };

        $extra = match ( $this->event ) {
            'delivery_reminder' => "Your pre-order is expected to be delivered on {$dueDate}.",
            'payment_received'  => "Paid: ৳{$paid}. Remaining due: ৳{$due}.",
            'ready'             => "Your pre-order for {$productName} is ready.",
            'cancelled'         => "Your pre-order {$invoice} has been cancelled.",
            default             => "Order: {$invoice}\nProduct: {$productName}\nStatus: {$lifecycle}\nExpected delivery: {$dueDate}",
        };

        $text = "{$label}\n{$extra}";

        return [
            'user_id'                => $notifiable->id ?? null,
            'text'                   => $text,
            'type'                   => 'pre_order',
            'event'                  => $this->event,
            'order_id'               => $this->order->id,
            'invoice'                => $invoice,
            'product_id'             => $this->order->product_id,
            'lifecycle'              => $lifecycle,
            'expected_delivery_date' => is_string( $this->order->expected_delivery_date )
                ? $this->order->expected_delivery_date
                : optional( $this->order->expected_delivery_date )->format( 'Y-m-d' ),
            'due_amount'             => (float) ( $this->order->due_amount ?? 0 ),
            'redirect'               => '/orders/' . $invoice,
        ];
    }
}
