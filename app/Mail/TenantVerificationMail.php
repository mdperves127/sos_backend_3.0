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
        return $this->subject( 'Verify your registration' )
            ->view( 'emails.tenant_verification' )
            ->with( [
                'token' => $this->code,
            ] );
    }
}
