<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TenantVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct( protected string $code )
    {
    }

    public function build()
    {
        return $this->subject( 'Tenant Registration Verification Code' )
            ->view( 'emails.reset_password' )
            ->with( [
                'token' => $this->code,
            ] );
    }
}
