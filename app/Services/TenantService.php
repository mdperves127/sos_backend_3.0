<?php

namespace App\Services;

use Illuminate\Support\Str;
use App\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

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

            if (env('APP_ENV') === 'local') {
                // For local environment, add .localhost if no domain extension
                if (!str_contains($domain, '.localhost') && !str_contains($domain, '.local') && !str_contains($domain, '.')) {
                    $domain = $domain . '.localhost';
                }
            } elseif (env('APP_ENV') === 'production') {
                // For production environment, add main domain if no domain extension
                $mainDomain = env('MAIN_DOMAIN');
                if (!str_contains($domain, '.') && $mainDomain) {
                    $domain = $domain . '.' . $mainDomain;
                }
            }
            // For other environments, keep the domain as is

            // Store password in session for later use
            session(['tenant_password_' . $tenantId => $data['password']]);
            \Log::info('TenantService: About to create tenant', [
                'tenant_id' => $tenantId,
                'type' => $data['type'] ?? 'NOT PROVIDED',
                'all_data' => $data
            ]);

            // Create the tenant without storing password in database
            $tenant = Tenant::create([
                'id' => $tenantId,
                'company_name' => $data['company_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'owner_name' => $data['owner_name'],
                'type' => $data['type'],
                'data' => null // Don't store password in database
            ]);

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
            $domainModel = Domain::create([
                'domain' => $domain,
                'tenant_id' => $tenantId,
            ]);

            // Extract subdomain part for cPanel API (just the subdomain, not the full domain)
            $subdomainPart = $data['domain'];
            if (env('APP_ENV') === 'production') {
                $mainDomain = env('MAIN_DOMAIN');
                // If domain contains the main domain, extract just the subdomain part
                if ($mainDomain && str_contains($domain, $mainDomain)) {
                    $subdomainPart = str_replace('.' . $mainDomain, '', $domain);
                }
            }

            // Create subdomain infrastructure based on environment
            // Wrap in try-catch so tenant creation doesn't fail if subdomain creation fails
            $subdomainResult = null;
            try {
                \Log::info('TenantService: Creating subdomain', [
                    'subdomain_part' => $subdomainPart,
                    'full_domain' => $domain,
                    'environment' => env('APP_ENV')
                ]);
                $subdomainResult = $this->cpanelService->createSubdomain($subdomainPart);

                \Log::info('TenantService: Subdomain creation result', [
                    'subdomain_result' => $subdomainResult
                ]);

                // Log warning if subdomain creation failed but don't throw exception
                if (isset($subdomainResult['status']) && $subdomainResult['status'] == 0) {
                    \Log::warning('TenantService: Subdomain creation failed but tenant was created', [
                        'tenant_id' => $tenantId,
                        'subdomain_result' => $subdomainResult
                    ]);
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

            return [
                'tenant' => $tenant,
                'domain' => $domainModel,
                'tenant_id' => $tenantId,
                'domain_url' => $domain,
                'subdomain' => $subdomainResult
            ];

        } catch (Exception $e) {
            throw new Exception('Failed to create tenant: ' . $e->getMessage());
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
}
