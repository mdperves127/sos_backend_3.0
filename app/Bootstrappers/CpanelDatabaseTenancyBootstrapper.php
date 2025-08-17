<?php

namespace App\Bootstrappers;

use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class CpanelDatabaseTenancyBootstrapper extends DatabaseTenancyBootstrapper
{
        /**
     * Bootstrap the tenant application.
     *
     * @param Tenant $tenant
     * @return void
     */
    public function bootstrap(Tenant $tenant): void
    {
        // Get the database name for the tenant
        $databaseName = $this->getDatabaseName($tenant);

        // Configure the tenant database connection
        $this->configureTenantConnection($tenant, $databaseName);

        // Call the parent method to complete the bootstrapping
        parent::bootstrap($tenant);
    }

    /**
     * Configure the tenant database connection
     *
     * @param Tenant $tenant
     * @param string $databaseName
     * @return void
     */
    protected function configureTenantConnection(Tenant $tenant, string $databaseName): void
    {
        // Set the database name
        config([
            'database.connections.tenant.database' => $databaseName,
        ]);

        // In production, use cPanel credentials for tenant databases
        if (env('APP_ENV') === 'production') {
            config([
                'database.connections.tenant.username' => env('CPANEL_USER'),
                'database.connections.tenant.password' => env('CPANEL_PASSWORD'),
            ]);
        }

        // Purge the connection to force Laravel to use the new configuration
        \DB::purge('tenant');
    }

    /**
     * Get the database name for the tenant
     *
     * @param Tenant $tenant
     * @return string
     */
    protected function getDatabaseName(Tenant $tenant): string
    {
        $prefix = config('tenancy.database.prefix');
        $suffix = config('tenancy.database.suffix');

        return $prefix . $tenant->getTenantKey() . $suffix;
    }
}
