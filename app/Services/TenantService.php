<?php

namespace App\Services;

use Illuminate\Support\Str;
use App\Mail\TenantWelcomeMail;
use App\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;

class TenantService
{
    protected CpanelService $cpanelService;

    public function __construct(CpanelService $cpanelService)
    {
        $this->cpanelService = $cpanelService;
    }

    /**
     * Create a new tenant with domain
     *
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function createTenant(array $data): array
    {
        try {
            // Generate tenant ID from domain name (clean version)
            $tenantId = preg_replace('/[^a-zA-Z0-9]/', '', $data['domain']);

            // Transform domain based on environment
            $domain = $data['domain'];

            // if (env('APP_ENV') === 'local') {
            //     // For local environment, add .localhost if no domain extension
            //     if (!str_contains($domain, '.localhost') && !str_contains($domain, '.local') && !str_contains($domain, '.')) {
            //         $domain = $domain . '.localhost';
            //     }
            // } else

            // Always build full tenant domain from MAIN_DOMAIN when a bare subdomain is given.
            $mainDomain = config( 'cpanel.main_domain' );
            if ( ! str_contains( $domain, '.' ) && $mainDomain ) {
                $domain = $domain . '.' . $mainDomain;
            }

            // Store password in session for later use
            session(['tenant_password_' . $tenantId => $data['password']]);
            \Log::info('TenantService: About to create tenant', [
                'tenant_id'    => $tenantId,
                'type'         => $data['type'] ?? 'NOT PROVIDED',
                'company_name' => $data['company_name'] ?? null,
                'email'        => $data['email'] ?? null,
                'domain'       => $data['domain'] ?? null,
            ]);

            // Create the tenant without storing password in database.
            // TenantCreated pipeline (CreateDatabase → Migrate → Seed → CreateTenantUser) runs here.
            try {
                $phone = $data['phone'] ?? $data['number'] ?? null;
                if ( is_string( $phone ) ) {
                    $phone = preg_replace( '/\s+/', '', $phone ) ?: null;
                }

                $tenant = Tenant::create([
                    'id' => $tenantId,
                    'company_name' => $data['company_name'],
                    'email' => $data['email'],
                    'phone' => $phone,
                    'address' => $data['address'] ?? null,
                    'owner_name' => $data['owner_name'],
                    'type' => $data['type'],
                    'status' => $data['status'] ?? 'pending',
                    'data' => null // Don't store password in database
                ]);
            } catch ( Exception $e ) {
                // Pipeline can leave an orphan central tenant row if DB create/migrate fails.
                $this->cleanupFailedTenant( $tenantId );
                throw $e;
            }

            \Log::info('TenantService: Tenant created with password in session', [
                'tenant_id' => $tenant->id,
                'password_stored_in_session' => true
            ]);

            \Log::info('TenantService: Tenant created successfully', [
                'tenant_id' => $tenant->id,
                'owner_name' => $tenant->owner_name,
                'email' => $tenant->email,
                'type' => $tenant->type,
                'type_attribute' => $tenant->attributes['type'] ?? 'NOT SET',
                'password_stored_in_session' => true
            ]);

            // Create the domain
            try {
                $domainModel = Domain::create([
                    'domain' => $domain,
                    'tenant_id' => $tenantId,
                ]);
            } catch ( Exception $e ) {
                $this->cleanupFailedTenant( $tenantId );
                throw $e;
            }

            // Extract subdomain part for cPanel API (just the subdomain, not the full domain)
            $subdomainPart = $data['domain'];
            $mainDomain    = config( 'cpanel.main_domain' );
            if ( $mainDomain && str_contains( $domain, $mainDomain ) ) {
                $subdomainPart = str_replace( '.' . $mainDomain, '', $domain );
            }
            // Guard against accidental FQDN being passed as the subdomain label.
            if ( str_contains( (string) $subdomainPart, '.' ) ) {
                $subdomainPart = explode( '.', (string) $subdomainPart )[0];
            }

            // Create subdomain infrastructure based on environment
            // Wrap in try-catch so tenant creation doesn't fail if subdomain creation fails
            $subdomainResult = null;
            try {
                if ( empty( $mainDomain ) ) {
                    \Log::error( 'TenantService: MAIN_DOMAIN is not configured; cannot create cPanel subdomain', [
                        'tenant_id' => $tenantId,
                        'domain'    => $domain,
                    ] );
                    $subdomainResult = [
                        'status'  => 0,
                        'error'   => 'MAIN_DOMAIN is not configured',
                        'message' => 'Set MAIN_DOMAIN in .env to your root domain (e.g. storeeb.com)',
                    ];
                } else {
                    \Log::info( 'TenantService: Creating subdomain', [
                        'subdomain_part' => $subdomainPart,
                        'full_domain'    => $domain,
                        'environment'    => config( 'app.env' ),
                    ] );
                    $subdomainResult = $this->cpanelService->createSubdomain( $subdomainPart );

                    \Log::info( 'TenantService: Subdomain creation result', [
                        'subdomain_result' => $subdomainResult,
                    ] );

                    // Log warning if subdomain creation failed but don't throw exception
                    if ( isset( $subdomainResult['status'] ) && (int) $subdomainResult['status'] === 0 ) {
                        \Log::warning( 'TenantService: Subdomain creation failed but tenant was created', [
                            'tenant_id'        => $tenantId,
                            'subdomain_result' => $subdomainResult,
                        ] );
                    }
                }
            } catch (\Exception $e) {
                // Log the error but don't fail tenant creation
                \Log::error('TenantService: Subdomain creation exception (non-blocking)', [
                    'tenant_id' => $tenantId,
                    'subdomain_part' => $subdomainPart,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                $subdomainResult = [
                    'status' => 0,
                    'error' => 'Exception during subdomain creation: ' . $e->getMessage()
                ];
            }

            $welcomeEmail = $this->sendWelcomeEmail( $tenant, $domain );

            return [
                'tenant' => $tenant,
                'domain' => $domainModel,
                'tenant_id' => $tenantId,
                'domain_url' => $domain,
                'subdomain' => $subdomainResult,
                'welcome_email' => $welcomeEmail,
            ];

        } catch (Exception $e) {
            throw new Exception('Failed to create tenant: ' . $e->getMessage());
        }
    }

    /**
     * Send welcome email after successful tenant registration.
     * Failures are logged only — registration must still succeed.
     *
     * @return array{sent: bool, error: string|null}
     */
    protected function sendWelcomeEmail( Tenant $tenant, string $domainUrl ): array
    {
        if ( empty( $tenant->email ) ) {
            \Log::warning( 'TenantService: Welcome email skipped — tenant has no email', [
                'tenant_id' => $tenant->id,
            ] );

            return [ 'sent' => false, 'error' => 'Tenant has no email address' ];
        }

        try {
            Mail::to( $tenant->email )->send( new TenantWelcomeMail(
                (string) ( $tenant->owner_name ?? '' ),
                (string) ( $tenant->company_name ?? '' ),
                (string) $tenant->email,
                $domainUrl,
                (string) ( $tenant->type ?? 'dropshipper' ),
            ) );

            \Log::info( 'TenantService: Welcome email sent', [
                'tenant_id' => $tenant->id,
                'email'     => $tenant->email,
                'mailer'    => config( 'mail.default' ),
            ] );

            return [ 'sent' => true, 'error' => null ];
        } catch ( \Throwable $e ) {
            \Log::error( 'TenantService: Welcome email failed (non-blocking)', [
                'tenant_id' => $tenant->id,
                'email'     => $tenant->email,
                'mailer'    => config( 'mail.default' ),
                'host'      => config( 'mail.mailers.smtp.host' ),
                'error'     => $e->getMessage(),
            ] );

            return [ 'sent' => false, 'error' => $e->getMessage() ];
        }
    }



    /**
     * Create subdomain only
     *
     * @param string $subdomain
     * @return array
     */
    public function createSubdomain(string $subdomain): array
    {
        return $this->cpanelService->createSubdomain($subdomain);
    }

    /**
     * Create database only
     *
     * @param string $dbname
     * @return array
     */
    public function createDatabase(string $dbname): array
    {
        return $this->cpanelService->createDatabase($dbname);
    }

    /**
     * Remove a partially created tenant without firing DeleteDatabase (DB may never have existed).
     */
    protected function cleanupFailedTenant( string $tenantId ): void
    {
        try {
            Domain::withoutEvents( function () use ( $tenantId ) {
                Domain::where( 'tenant_id', $tenantId )->delete();
            } );

            Tenant::withoutEvents( function () use ( $tenantId ) {
                Tenant::where( 'id', $tenantId )->delete();
            } );

            \Log::warning( 'TenantService: cleaned up failed tenant registration', [
                'tenant_id' => $tenantId,
            ] );
        } catch ( \Throwable $e ) {
            \Log::error( 'TenantService: failed to clean up orphan tenant', [
                'tenant_id' => $tenantId,
                'error'     => $e->getMessage(),
            ] );
        }
    }
}
