<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TenantWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $ownerName,
        public string $companyName,
        public string $email,
        public string $domainUrl,
        public string $type = 'dropshipper'
    ) {
        // Prefer public storefront host from .env over API host in welcome links.
        $apiHost = (string) (
            config( 'app.api_public_host' )
            ?: parse_url( (string) config( 'app.url' ), PHP_URL_HOST )
        );
        $frontendHost = (string) (
            config( 'app.frontend_public_host' )
            ?: parse_url( (string) config( 'app.maindomain' ), PHP_URL_HOST )
            ?: parse_url( (string) config( 'app.redirecturl' ), PHP_URL_HOST )
            ?: config( 'cpanel.main_domain' )
        );

        if ( $apiHost !== '' && $frontendHost !== '' && strcasecmp( $apiHost, $frontendHost ) !== 0 ) {
            $this->domainUrl = str_ireplace( $apiHost, $frontendHost, $domainUrl );
        }
    }

    public function build()
    {
        $data = [
            'ownerName'   => $this->ownerName,
            'companyName' => $this->companyName,
            'email'       => $this->email,
            'domainUrl'   => $this->domainUrl,
            'type'        => $this->type,
        ];

        return $this->subject( 'Welcome - your account is ready' )
            ->view( 'emails.tenant_welcome', $data )
            ->text( 'emails.text.tenant_welcome', $data );
    }
}
