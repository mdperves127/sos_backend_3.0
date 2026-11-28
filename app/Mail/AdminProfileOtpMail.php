<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AdminProfileOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    private const OTP_TTL_MINUTES = 5;

    public function __construct(
        protected string $code,
        protected string $purpose,
        protected ?string $userName = null
    ) {
    }

    public function build()
    {
        $isEmailChange = $this->purpose === 'email_change';

        $data = [
            'token'          => $this->code,
            'userName'       => $this->userName,
            'expiresMinutes' => self::OTP_TTL_MINUTES,
            'title'          => $isEmailChange ? 'Confirm email change' : 'Confirm password change',
            'heading'        => $isEmailChange ? 'Confirm your new email' : 'Confirm password change',
            'intro'          => $isEmailChange
                ? 'You requested to change your admin account email. Enter this code to confirm the new address.'
                : 'You requested to change your admin account password. Enter this code to continue.',
        ];

        return $this->subject( $isEmailChange ? 'Confirm your email change' : 'Confirm your password change' )
            ->view( 'emails.admin_profile_otp', $data );
    }
}
