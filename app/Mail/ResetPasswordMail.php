<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    protected $token;

    /**
     * Create a new message instance.
     *
     * @param  string|int  $token
     * @return void
     */
    public function __construct( $token )
    {
        $this->token = $token;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject( 'Reset Password Mail' )
            ->view( 'emails.reset_password' )
            ->with( [
                'token' => $this->token,
            ] );
    }
}
