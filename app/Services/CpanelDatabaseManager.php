<?php

namespace App\Services;

use RuntimeException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;

class CpanelDatabaseManager extends MySQLDatabaseManager
{
    protected CpanelService $cpanelService;

    public function __construct(CpanelService $cpanelService)
    {
        $this->cpanelService = $cpanelService;
    }

    /**
     * Create a database for a tenant.
     * Tries cPanel first, then direct MySQL CREATE DATABASE as fallback.
     * Throws if the database still does not exist — Stancl ignores a false return
     * and would otherwise continue into MigrateDatabase.
     */
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $databaseName = $tenant->database()->getName() ?: $this->getDatabaseName( $tenant );
        $errors       = [];

        // 1) cPanel API
        try {
            $result = $this->cpanelService->createDatabase( $databaseName );

            if ( (int) ( $result['status'] ?? 0 ) === 1 ) {
                $fullName = (string) ( $result['full_name'] ?? $databaseName );

                if ( $fullName !== '' && $fullName !== $databaseName ) {
                    $tenant->setInternal( 'db_name', $fullName );
                    if ( $tenant->exists ) {
                        $tenant->save();
                    }
                    $databaseName = $fullName;
                }

                if ( $this->databaseLooksReady( $databaseName ) ) {
                    return true;
                }

                // cPanel said OK but we cannot see it yet — still treat as success
                // (INFORMATION_SCHEMA visibility can lag / be restricted).
                \Log::warning( 'cPanel reported DB created but existence check failed; continuing', [
                    'tenant_id'     => $tenant->getTenantKey(),
                    'database_name' => $databaseName,
                ] );

                return true;
            }

            $errors[] = $result['error']
                ?? $this->stringifyErrors( $result['database']['errors'] ?? null )
                ?? 'cPanel create_database returned status 0';

            \Log::error( 'cPanel database creation failed', [
                'tenant_id'     => $tenant->getTenantKey(),
                'database_name' => $databaseName,
                'result'        => $result,
            ] );
        } catch ( \Throwable $e ) {
            $errors[] = 'cPanel exception: ' . $e->getMessage();
            \Log::error( 'cPanel database creation exception', [
                'tenant_id' => $tenant->getTenantKey(),
                'error'     => $e->getMessage(),
            ] );
        }

        // 2) Direct MySQL (works when the app DB user has CREATE privilege, e.g. local)
        try {
            if ( parent::createDatabase( $tenant ) ) {
                return true;
            }
            $errors[] = 'Direct MySQL CREATE DATABASE returned false';
        } catch ( \Throwable $e ) {
            $errors[] = 'Direct MySQL CREATE DATABASE failed: ' . $e->getMessage();
            \Log::error( 'Direct MySQL database creation failed', [
                'tenant_id'     => $tenant->getTenantKey(),
                'database_name' => $databaseName,
                'error'         => $e->getMessage(),
            ] );
        }

        if ( $this->databaseLooksReady( $databaseName ) ) {
            return true;
        }

        throw new RuntimeException(
            'Failed to create tenant database `' . $databaseName . '`: ' . implode( ' | ', $errors )
        );
    }

    /**
     * Delete a database for a tenant
     */
    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $databaseName = $tenant->database()->getName() ?: $this->getDatabaseName( $tenant );

        try {
            $cpanelUser     = (string) config( 'cpanel.user', '' );
            $cpanelPassword = (string) config( 'cpanel.password', '' );
            $cpanelHost     = (string) config( 'cpanel.host', '' );

            if ( $cpanelUser && $cpanelPassword && $cpanelHost ) {
                $dbPrefix     = $this->cpanelService->getMysqlPrefix();
                $dbNameForApi = str_starts_with( $databaseName, $dbPrefix )
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

                $result = json_decode( (string) $response, true );
                if ( isset( $result['status'] ) && (int) $result['status'] === 1 ) {
                    return true;
                }

                \Log::error( 'cPanel database deletion failed', [
                    'tenant_id'     => $tenant->getTenantKey(),
                    'database_name' => $databaseName,
                    'result'        => $result,
                ] );
            }
        } catch ( \Throwable $e ) {
            \Log::error( 'cPanel database deletion exception', [
                'tenant_id' => $tenant->getTenantKey(),
                'error'     => $e->getMessage(),
            ] );
        }

        try {
            return parent::deleteDatabase( $tenant );
        } catch ( \Throwable $e ) {
            \Log::error( 'Direct MySQL database deletion failed', [
                'tenant_id' => $tenant->getTenantKey(),
                'error'     => $e->getMessage(),
            ] );

            return false;
        }
    }

    protected function getDatabaseName(TenantWithDatabase $tenant): string
    {
        $prefix = config( 'tenancy.database.prefix' );
        $suffix = config( 'tenancy.database.suffix' );

        return $prefix . $tenant->getTenantKey() . $suffix;
    }

    private function databaseLooksReady( string $databaseName ): bool
    {
        try {
            return $this->databaseExists( $databaseName );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    private function stringifyErrors( $errors ): ?string
    {
        if ( is_array( $errors ) ) {
            return implode( '; ', array_map( 'strval', $errors ) );
        }

        if ( is_string( $errors ) && $errors !== '' ) {
            return $errors;
        }

        return null;
    }
}
