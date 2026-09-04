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

        // Avoid per-request info logs under load (was amplifying I/O at high concurrency).
        if ( config( 'app.debug' ) ) {
            \Log::debug( 'Tenancy bootstrapped', [
                'tenant_id'     => $tenant->getTenantKey(),
                'database_name' => $databaseName,
            ] );
        }
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
        } catch (\Exception $e) {
            // Expected if the tenant database does not exist yet
            if ( config( 'app.debug' ) ) {
                \Log::debug( 'Tenant database connection failed', [
                    'database_name' => $actualDatabaseName,
                    'error'         => $e->getMessage(),
                ] );
            }
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
