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

            // Create the tenant without storing password in database
            $tenant = Tenant::create([
                'id' => $tenantId,
                'company_name' => $data['company_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'owner_name' => $data['owner_name'],
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
                'password_stored_in_session' => true
            ]);

            // Create the domain
            $domainModel = Domain::create([
                'domain' => $domain,
                'tenant_id' => $tenantId,
            ]);

            // Create subdomain infrastructure based on environment
            $subdomainResult = $this->cpanelService->createSubdomain($data['domain']);

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
