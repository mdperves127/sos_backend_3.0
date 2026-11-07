<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    protected $token;

    protected ?string $userName;

    protected int $expiresMinutes;

    /**
     * Create a new message instance.
     *
     * @param  string|int  $token
     * @param  string|null  $userName
     * @param  int  $expiresMinutes  Must match existing reset OTP validation window.
     * @return void
     */
    public function __construct( $token, ?string $userName = null, int $expiresMinutes = 5 )
    {
        $this->token = $token;
        $this->userName = $userName;
        $this->expiresMinutes = $expiresMinutes;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $data = [
            'token'          => $this->token,
            'userName'       => $this->userName,
            'expiresMinutes' => $this->expiresMinutes,
        ];

        return $this->subject( 'Reset your password' )
            ->view( 'emails.reset_password', $data )
            ->text( 'emails.text.reset_password', $data );
    }
}
