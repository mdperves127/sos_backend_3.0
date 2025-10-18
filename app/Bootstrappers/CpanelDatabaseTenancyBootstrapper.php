<?php

namespace App\Bootstrappers;

use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Illuminate\Support\Facades\Log;

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

        // Ensure the default connection is set to tenant
        \DB::setDefaultConnection('tenant');

        // Force Laravel to use tenant connection by setting config
        config(['database.default' => 'tenant']);

        // Log for debugging
        \Log::info('Tenancy bootstrapped', [
            'tenant_id' => $tenant->getTenantKey(),
            'database_name' => $databaseName,
            'default_connection' => \DB::getDefaultConnection(),
            'tenant_connection_database' => \DB::connection('tenant')->getDatabaseName()
        ]);
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
        // Get the actual database name from tenant data if available
        $actualDatabaseName = $tenant->data['tenancy_db_name'] ?? $databaseName;

        \Log::info('Configuring tenant connection', [
            'tenant_id' => $tenant->getTenantKey(),
            'provided_database_name' => $databaseName,
            'actual_database_name' => $actualDatabaseName,
            'tenant_data' => $tenant->data
        ]);

        // Set the database name and ensure connection isolation
        config([
            'database.connections.tenant.database' => $actualDatabaseName,
            'database.connections.tenant.host' => env('DB_HOST', '127.0.0.1'),
            'database.connections.tenant.port' => env('DB_PORT', '3306'),
            'database.connections.tenant.driver' => 'mysql',
            'database.connections.tenant.username' => env('DB_USERNAME', 'root'),
            'database.connections.tenant.password' => env('DB_PASSWORD', ''),
            'database.connections.tenant.charset' => 'utf8mb4',
            'database.connections.tenant.collation' => 'utf8mb4_unicode_ci',
            'database.connections.tenant.strict' => false,
        ]);

        // In production, use cPanel credentials for tenant databases
        if (env('APP_ENV') === 'production') {
            config([
                'database.connections.tenant.username' => env('CPANEL_USER'),
                'database.connections.tenant.password' => env('CPANEL_PASSWORD'),
            ]);
        }

        // Purge the connection to force Laravel to use the new configuration
        try {
            \DB::purge('tenant');
        } catch (\Exception $e) {
            // Connection doesn't exist yet, which is fine
            // Laravel will create it when needed
        }

        // Force Laravel to reinitialize the connection with new config
        try {
            \DB::connection('tenant')->getPdo();
            \Log::info('Tenant database connection successful', [
                'database_name' => $actualDatabaseName
            ]);
        } catch (\Exception $e) {
            // This is expected if the database doesn't exist yet
            \Log::info('Tenant database connection failed (expected if DB not exists)', [
                'database_name' => $actualDatabaseName,
                'error' => $e->getMessage()
            ]);
        }
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
