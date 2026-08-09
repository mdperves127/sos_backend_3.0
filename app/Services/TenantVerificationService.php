<?php

namespace App\Services;

use App\Mail\TenantVerificationMail;
use App\Models\Tenant;
use App\Models\TenantVerification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class TenantVerificationService
{
    private const OTP_TTL_MINUTES = 5;

    public function send( string $channel, ?string $email = null, ?string $phone = null ): array
    {
        $this->ensureIdentifierIsAvailable( $channel, $email, $phone );

        $code         = (string) random_int( 100000, 999999 );
        $verification = $this->findOrCreateVerification( $channel, $email, $phone );

        if ( $channel === 'email' ) {
            $verification->fill( [
                'email'                => $email,
                'email_verify_code'    => $code,
                'email_verify_code_at' => now(),
                'email_verified_at'    => null,
            ] )->save();

            Mail::to( $email )->send( new TenantVerificationMail( $code ) );

            return [
                'channel'    => 'email',
                'send_to'    => $email,
                'expires_in' => self::OTP_TTL_MINUTES * 60,
            ];
        }

        $phone = $this->normalizePhone( $phone );

        try {
            SmsService::sendSms( [
                'number'      => $phone,
                'verify_code' => $code,
            ] );
        } catch ( \RuntimeException $e ) {
            throw ValidationException::withMessages( [
                'phone' => [$e->getMessage()],
            ] );
        }

        $verification->fill( [
            'phone'                => $phone,
            'phone_verify_code'    => $code,
            'phone_verify_code_at' => now(),
            'phone_verified_at'    => null,
        ] )->save();

        return [
            'channel'    => 'sms',
            'send_to'    => $phone,
            'expires_in' => self::OTP_TTL_MINUTES * 60,
        ];
    }

    public function verify( string $channel, string $code, ?string $email = null, ?string $phone = null ): array
    {
        if ( $channel === 'email' ) {
            $verification = TenantVerification::where( 'email', $email )->first();
            $storedCode   = $verification?->email_verify_code;
            $sentAt       = $verification?->email_verify_code_at;
            $verifiedField = 'email_verified_at';
        } else {
            $phone        = $this->normalizePhone( $phone );
            $verification = TenantVerification::where( 'phone', $phone )->first();
            $storedCode   = $verification?->phone_verify_code;
            $sentAt       = $verification?->phone_verify_code_at;
            $verifiedField = 'phone_verified_at';
        }

        if ( ! $verification || ! $storedCode || $storedCode !== $code ) {
            throw ValidationException::withMessages( [
                'code' => ['Invalid verification code.'],
            ] );
        }

        if ( ! $sentAt || Carbon::parse( $sentAt )->addMinutes( self::OTP_TTL_MINUTES )->isPast() ) {
            throw ValidationException::withMessages( [
                'code' => ['Verification code has expired. Please request a new one.'],
            ] );
        }

        $verification->{$verifiedField} = now();
        $verification->save();

        return [
            'channel'     => $channel,
            'verified_at' => $verification->{$verifiedField},
            'email'       => $verification->email,
            'phone'       => $verification->phone,
        ];
    }

    public function resend( string $channel, ?string $email = null, ?string $phone = null ): array
    {
        return $this->send( $channel, $email, $phone );
    }

    private function findOrCreateVerification( string $channel, ?string $email, ?string $phone ): TenantVerification
    {
        if ( $channel === 'email' ) {
            return TenantVerification::firstOrNew( ['email' => $email] );
        }

        return TenantVerification::firstOrNew( ['phone' => $this->normalizePhone( $phone )] );
    }

    private function ensureIdentifierIsAvailable( string $channel, ?string $email, ?string $phone ): void
    {
        if ( $channel === 'email' && Tenant::where( 'email', $email )->exists() ) {
            throw ValidationException::withMessages( [
                'email' => ['This email is already registered.'],
            ] );
        }

        if ( $channel === 'sms' && Tenant::where( 'phone', $this->normalizePhone( $phone ) )->exists() ) {
            throw ValidationException::withMessages( [
                'phone' => ['This phone number is already registered.'],
            ] );
        }
    }

    private function normalizePhone( ?string $phone ): ?string
    {
        if ( $phone === null ) {
            return null;
        }

        return preg_replace( '/\s+/', '', $phone );
    }
}
