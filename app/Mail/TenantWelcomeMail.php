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
        // Welcome email must show public storefront domain (affsell.com), not API host (affsell.org).
        $this->domainUrl = str_ireplace( 'affsell.org', 'affsell.com', $domainUrl );
    }

    public function build()
    {
        return $this->subject( 'Welcome — your account is ready' )
            ->view( 'emails.tenant_welcome' )
            ->with( [
                'ownerName'   => $this->ownerName,
                'companyName' => $this->companyName,
                'email'       => $this->email,
                'domainUrl'   => $this->domainUrl,
                'type'        => $this->type,
            ] );
    }
}
