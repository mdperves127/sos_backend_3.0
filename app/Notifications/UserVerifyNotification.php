<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserVerifyNotification extends Notification
{
    use Queueable;

    public $user;

    /** Matches AuthController user verification OTP window (addMinutes(5)). */
    private const OTP_TTL_MINUTES = 5;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct( $user )
    {
        $this->user = $user;
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
        $user = $this->user;
        $data = [
            'user'           => $user,
            'expiresMinutes' => self::OTP_TTL_MINUTES,
        ];

        return ( new MailMessage )
            ->subject( 'Verify your email' )
            ->view( [
                'html' => 'emails.user_verification',
                'text' => 'emails.text.user_verification',
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
