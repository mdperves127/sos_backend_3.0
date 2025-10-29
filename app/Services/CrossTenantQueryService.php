<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class CrossTenantQueryService
{
    /**
     * Query a model across all tenants
     *
     * @param string $modelClass The fully qualified model class name
     * @param callable $queryCallback Callback to build the query
     * @param array $selectFields Fields to select from the model
     * @return Collection
     */
    public static function queryAllTenants(string $modelClass, callable $queryCallback, array $selectFields = ['*']): Collection
    {
        $results = collect();
        $model = new $modelClass;

        // Get all tenants (vendors/merchants)
        $tenants = \App\Models\Tenant::where('type', 'merchant')->get();

        \Log::info('CrossTenantQuery: Found tenants', ['count' => $tenants->count()]);

        foreach ($tenants as $tenant) {
            try {
                // Set the tenant database connection
                $connectionName = self::getTenantConnectionName($tenant);
                $databaseName = self::getDatabaseName($tenant);

                \Log::info("Querying tenant", [
                    'tenant_id' => $tenant->id,
                    'connection' => $connectionName,
                    'database' => $databaseName
                ]);

                self::configureTenantConnection($tenant, $connectionName);

                // Run the query for this tenant - use DB facade to bypass model connection
                $tableName = $model->getTable();
                $baseQuery = DB::connection($connectionName)->table($tableName);

                // Apply the callback query builder
                if ($queryCallback) {
                    $baseQuery = $queryCallback($baseQuery);
                }

                $tenantResults = $baseQuery->get();

                \Log::info("Tenant query results", [
                    'tenant_id' => $tenant->id,
                    'count' => $tenantResults->count()
                ]);

                // Add tenant context to each result
                $tenantResults->transform(function ($item) use ($tenant) {
                    $domain = $tenant->domains()->first();
                    $item->tenant_id = $tenant->id;
                    $item->tenant_domain = $domain?->domain;
                    $item->tenant_name = $tenant->company_name;
                    $item->tenant_type = $tenant->type;
                    $item->tenant_email = $tenant->email;
                    $item->tenant_owner = $tenant->owner_name;
                    $item->tenant_phone = $tenant->phone;
                    return $item;
                });

                $results = $results->merge($tenantResults);
            } catch (\Exception $e) {
                \Log::warning("Failed to query tenant {$tenant->id}: " . $e->getMessage());
                \Log::warning("Exception details", ['exception' => $e->getTraceAsString()]);
                continue;
            } finally {
                // Reconnect to central database
                DB::setDefaultConnection('mysql');
            }
        }

        \Log::info('CrossTenantQuery: Total results', ['count' => $results->count()]);

        return $results;
    }

    /**
     * Query a specific tenant's database
     *
     * @param \App\Models\Tenant $tenant
     * @param string $modelClass
     * @param callable $queryCallback
     * @param array $selectFields
     * @return Collection
     */
    public static function queryTenant($tenant, string $modelClass, callable $queryCallback, array $selectFields = ['*']): Collection
    {
        $model = new $modelClass;
        $connectionName = self::getTenantConnectionName($tenant);

        self::configureTenantConnection($tenant, $connectionName);

        return $model->setConnection($connectionName)->newQuery()
            ->select($selectFields)
            ->when($queryCallback, $queryCallback)
            ->get();
    }

    /**
     * Get paginated results across all tenants
     *
     * @param string $modelClass
     * @param callable $queryCallback
     * @param int $perPage
     * @param array $selectFields
     * @return array
     */
    public static function queryAllTenantsPaginated(string $modelClass, callable $queryCallback, int $perPage = 10, array $selectFields = ['*']): array
    {
        $allResults = self::queryAllTenants($modelClass, $queryCallback, $selectFields);

        // Manually paginate the collection
        $page = request()->get('page', 1);
        $offset = ($page - 1) * $perPage;
        $paginatedResults = $allResults->slice($offset, $perPage);

        return [
            'data' => $paginatedResults->values(),
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $allResults->count(),
            'last_page' => ceil($allResults->count() / $perPage),
            'from' => $offset + 1,
            'to' => min($offset + $perPage, $allResults->count()),
        ];
    }

    /**
     * Configure tenant database connection
     *
     * @param \App\Models\Tenant $tenant
     * @param string $connectionName
     * @return void
     */
    protected static function configureTenantConnection($tenant, string $connectionName): void
    {
        $databaseName = self::getDatabaseName($tenant);

        config([
            'database.connections.' . $connectionName => [
                'driver' => 'mysql',
                'host' => config('database.connections.mysql.host'),
                'port' => config('database.connections.mysql.port'),
                'database' => $databaseName,
                'username' => config('database.connections.mysql.username'),
                'password' => config('database.connections.mysql.password'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => false,
            ]
        ]);

        DB::purge($connectionName);
    }

    /**
     * Get unique connection name for tenant
     *
     * @param \App\Models\Tenant $tenant
     * @return string
     */
    protected static function getTenantConnectionName($tenant): string
    {
        return 'tenant_' . $tenant->id;
    }

    /**
     * Get database name for tenant
     *
     * @param \App\Models\Tenant $tenant
     * @return string
     */
    protected static function getDatabaseName($tenant): string
    {
        return 'sosanik_tenant_' . $tenant->id;
    }
}

