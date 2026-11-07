<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TenantVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /** Matches TenantVerificationService::OTP_TTL_MINUTES */
    private const OTP_TTL_MINUTES = 5;

    public function __construct(
        protected string $code,
        protected ?string $userName = null
    ) {
    }

    public function build()
    {
        $data = [
            'token'          => $this->code,
            'userName'       => $this->userName,
            'expiresMinutes' => self::OTP_TTL_MINUTES,
        ];

        return $this->subject( 'Verify your registration' )
            ->view( 'emails.tenant_verification', $data )
            ->text( 'emails.text.tenant_verification', $data );
    }
}
