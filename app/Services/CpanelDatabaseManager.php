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
        return $this->createDatabaseViaCpanel( $tenant );
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
            $databaseName = $this->getDatabaseName( $tenant );
            $result       = $this->cpanelService->createDatabase( $databaseName );

            if (
                ( isset( $result['status'] ) && (int) $result['status'] === 1 )
                || ( isset( $result['database']['status'] ) && (int) $result['database']['status'] === 1 )
                || ( isset( $result['assignment']['status'] ) && (int) $result['assignment']['status'] === 1 )
            ) {
                return true;
            }

            \Log::error( 'cPanel database creation failed', [
                'tenant_id'     => $tenant->getTenantKey(),
                'database_name' => $databaseName,
                'result'        => $result,
            ] );

            return false;
        } catch ( \Exception $e ) {
            \Log::error( 'cPanel database creation exception', [
                'tenant_id' => $tenant->getTenantKey(),
                'error'     => $e->getMessage(),
            ] );

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
        return $this->deleteDatabaseViaCpanel( $tenant );
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
            $databaseName   = $this->getDatabaseName( $tenant );
            $cpanelUser     = env( 'CPANEL_USER' );
            $cpanelPassword = env( 'CPANEL_PASSWORD' );
            $cpanelHost     = env( 'CPANEL_HOST' );
            $dbPrefix       = config( 'tenancy.database.prefix', $cpanelUser . '_' );
            $dbNameForApi   = str_starts_with( $databaseName, $dbPrefix )
                ? substr( $databaseName, strlen( $dbPrefix ) )
                : $databaseName;

            $deleteDbUrl = 'https://' . $cpanelHost . ':2083/execute/Mysql/delete_database?name=' . urlencode( $dbNameForApi );
            $ch          = curl_init();
            curl_setopt( $ch, CURLOPT_URL, $deleteDbUrl );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $ch, CURLOPT_USERPWD, "$cpanelUser:$cpanelPassword" );
            curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
            $response = curl_exec( $ch );
            curl_close( $ch );

            $result = json_decode( $response, true );

            if ( isset( $result['status'] ) && (int) $result['status'] === 1 ) {
                return true;
            }

            \Log::error( 'cPanel database deletion failed', [
                'tenant_id'     => $tenant->getTenantKey(),
                'database_name' => $databaseName,
                'result'        => $result,
            ] );

            return false;
        } catch ( \Exception $e ) {
            \Log::error( 'cPanel database deletion exception', [
                'tenant_id' => $tenant->getTenantKey(),
                'error'     => $e->getMessage(),
            ] );

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
