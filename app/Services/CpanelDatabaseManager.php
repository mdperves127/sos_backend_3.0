<?php

namespace App\Services;

use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class CpanelDatabaseManager extends MySQLDatabaseManager
{
    protected CpanelService $cpanelService;

    public function __construct(CpanelService $cpanelService)
    {
        $this->cpanelService = $cpanelService;
    }

    /**
     * Create a database for a tenant using cPanel API
     *
     * @param TenantWithDatabase $tenant
     * @return bool
     */
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        // Check environment
        if (env('APP_ENV') === 'local') {
            // For local environment, use the parent method (direct MySQL)
            return parent::createDatabase($tenant);
        } elseif (env('APP_ENV') === 'production') {
            // For production environment, use cPanel API
            return $this->createDatabaseViaCpanel($tenant);
        } else {
            // For other environments, return false
            return false;
        }
    }

    /**
     * Create database using cPanel API
     *
     * @param TenantWithDatabase $tenant
     * @return bool
     */
    private function createDatabaseViaCpanel(TenantWithDatabase $tenant): bool
    {
        try {
            $databaseName = $this->getDatabaseName($tenant);
            $result = $this->cpanelService->createDatabase($databaseName);

            // Check if the result indicates success
            if (isset($result['database']) && isset($result['database']['status']) && $result['database']['status'] == 1) {
                return true;
            }

            // Log the error for debugging
            \Log::error('cPanel database creation failed', [
                'tenant_id' => $tenant->getTenantKey(),
                'database_name' => $databaseName,
                'result' => $result
            ]);

            return false;
        } catch (\Exception $e) {
            \Log::error('cPanel database creation exception', [
                'tenant_id' => $tenant->getTenantKey(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }



    /**
     * Delete a database for a tenant
     *
     * @param TenantWithDatabase $tenant
     * @return bool
     */
    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        // Check environment
        if (env('APP_ENV') === 'local') {
            // For local environment, use the parent method (direct MySQL)
            return parent::deleteDatabase($tenant);
        } elseif (env('APP_ENV') === 'production') {
            // For production environment, use cPanel API
            return $this->deleteDatabaseViaCpanel($tenant);
        } else {
            // For other environments, return false
            return false;
        }
    }

    /**
     * Delete database using cPanel API
     *
     * @param TenantWithDatabase $tenant
     * @return bool
     */
    private function deleteDatabaseViaCpanel(TenantWithDatabase $tenant): bool
    {
        try {
            $databaseName = $this->getDatabaseName($tenant);
            $cpanelUser = env('CPANEL_USER');
            $cpanelPassword = env('CPANEL_PASSWORD');
            $cpanelHost = env('CPANEL_HOST');

            // Delete the database using cPanel API
            $deleteDbUrl = "https://$cpanelHost:2083/execute/Mysql/delete_database?name=$databaseName";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $deleteDbUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, "$cpanelUser:$cpanelPassword");
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            curl_close($ch);

            $result = json_decode($response, true);

            if (isset($result['status']) && $result['status'] == 1) {
                return true;
            }

            // Log the error for debugging
            \Log::error('cPanel database deletion failed', [
                'tenant_id' => $tenant->getTenantKey(),
                'database_name' => $databaseName,
                'result' => $result
            ]);

            return false;
        } catch (\Exception $e) {
            \Log::error('cPanel database deletion exception', [
                'tenant_id' => $tenant->getTenantKey(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get the database name for the tenant
     *
     * @param TenantWithDatabase $tenant
     * @return string
     */
    protected function getDatabaseName(TenantWithDatabase $tenant): string
    {
        $prefix = config('tenancy.database.prefix');
        $suffix = config('tenancy.database.suffix');

        return $prefix . $tenant->getTenantKey() . $suffix;
    }
}
