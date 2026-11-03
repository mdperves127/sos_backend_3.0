<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification
{
    use Queueable;

    protected $product;

    protected $user;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct( $product, $user )
    {
        $this->product = $product;
        $this->user    = $user;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via( $notifiable )
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail( $notifiable )
    {
        $data = [
            'product' => $this->product,
            'user'    => $this->user,
        ];

        return ( new MailMessage )
            ->subject( 'Low stock alert' )
            ->view( [
                'html' => 'emails.low_stock',
                'text' => 'emails.text.low_stock',
            ], $data );
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray( $notifiable )
    {
        return [
            //
        ];
    }
}
