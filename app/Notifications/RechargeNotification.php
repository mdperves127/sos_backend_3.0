<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RechargeNotification extends Notification
{
    use Queueable;
    public $user, $amount, $trxid;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($user, $amount, $trxid)
    {
        $this->user = $user;
        $this->amount = $amount;
        $this->trxid = $trxid;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        // return (new MailMessage)
        //             ->line('The introduction to the notification.')
        //             ->action('Notification Action', url('/'))
        //             ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $redirect = '';

        return [
            'user_id' => $this->user['id'],
            'text' => 'Congratulations! Your amount ' . $this->amount . ' recharge was successful! TranxID : '.$this->trxid,
            'redirect' => $redirect
        ];
    }
}
