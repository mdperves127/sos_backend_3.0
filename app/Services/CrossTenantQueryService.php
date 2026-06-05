<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
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
    public static function queryAllTenants( string $modelClass, callable $queryCallback, array $selectFields = ['*'] ): Collection {
        return self::queryTenantsByType( 'merchant', $modelClass, $queryCallback, $selectFields );
    }

    /**
     * Query a model across all tenant databases for a given tenant type.
     */
    public static function queryTenantsByType( string $tenantType, string $modelClass, callable $queryCallback, array $selectFields = ['*'] ): Collection {
        $results = collect();
        $model   = new $modelClass;

        $tenants = Tenant::on( 'mysql' )->where( 'type', $tenantType )->get();

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
                    $result = $queryCallback($baseQuery);
                    // Only update $baseQuery if callback returns a value, otherwise keep using $baseQuery
                    if ($result !== null) {
                        $baseQuery = $result;
                    }
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

    public static function queryAllDropshipperTenants( string $modelClass, callable $queryCallback, array $selectFields = ['*'] ): Collection {
        return self::queryTenantsByType( 'dropshipper', $modelClass, $queryCallback, $selectFields );
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
     * Get a single model instance from a specific tenant's database
     * This method returns a full Eloquent model with relationship support
     *
     * @param string|int $tenantId The tenant ID (can be string or int)
     * @param string $modelClass The fully qualified model class name
     * @param callable $queryCallback Callback to build the query (receives Eloquent query builder)
     * @return mixed|null The model instance or null if not found
     */
    public static function getSingleFromTenant(string|int $tenantId, string $modelClass, callable $queryCallback = null)
    {
        // Find tenant by ID (accepts both string and int)
        $tenant = Tenant::on('mysql')->find($tenantId);

        if (!$tenant) {
            return null;
        }

        // Store the original connection to restore it later
        $originalConnection = DB::getDefaultConnection();

        try {
            $connectionName = self::getTenantConnectionName($tenant);
            self::configureTenantConnection($tenant, $connectionName);

            $model = new $modelClass;
            $query = $model->setConnection($connectionName)->newQuery();

            // Apply the callback if provided
            if ($queryCallback) {
                $queryCallback($query);
            }

            $result = $query->first();

            // Add tenant context if result found
            if ($result) {
                $domain = $tenant->domains()->first();
                $result->tenant_id = $tenant->id;
                $result->tenant_domain = $domain?->domain;
                $result->tenant_name = $tenant->company_name;
            }

            return $result;
        } catch (\Exception $e) {
            \Log::error("Failed to query single model from tenant {$tenantId}: " . $e->getMessage());
            return null;
        } finally {
            // Restore the original connection (could be 'tenant', 'mysql', etc.)
            DB::setDefaultConnection($originalConnection);
            config(['database.default' => $originalConnection]);
        }
    }

    /**
     * Save a model instance to a specific tenant's database
     *
     * @param string|int $tenantId The tenant ID where to save the data
     * @param string $modelClass The fully qualified model class name
     * @param callable $dataCallback Callback to set model data (receives model instance)
     * @return mixed|null The saved model instance or null if failed
     */
    public static function saveToTenant(string|int $tenantId, string $modelClass, callable $dataCallback)
    {
        // Find tenant by ID (accepts both string and int)
        $tenant = Tenant::on('mysql')->find($tenantId);

        if (!$tenant) {
            \Log::error("Tenant not found for saving data", ['tenant_id' => $tenantId]);
            return null;
        }

        // Store the original connection to restore it later
        $originalConnection = DB::getDefaultConnection();

        try {
            $connectionName = self::getTenantConnectionName($tenant);
            self::configureTenantConnection($tenant, $connectionName);

            // Create model instance and set connection to target tenant
            $model = new $modelClass;
            $model->setConnection($connectionName);

            // Apply the callback to set data
            $dataCallback($model);

            // Save to the target tenant database
            $saved = $model->save();

            if (!$saved) {
                \Log::error("Failed to save model to tenant", [
                    'tenant_id' => $tenantId,
                    'model_class' => $modelClass
                ]);
                return null;
            }

            \Log::info("Model saved successfully to tenant", [
                'tenant_id' => $tenantId,
                'model_id' => $model->id,
                'model_class' => $modelClass,
                'database' => DB::connection($connectionName)->getDatabaseName()
            ]);

            return $model;
        } catch (\Exception $e) {
            \Log::error("Exception saving model to tenant {$tenantId}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        } finally {
            // Restore the original connection
            DB::setDefaultConnection($originalConnection);
            config(['database.default' => $originalConnection]);
        }
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
    /**
     * Resolve the physical database name for a tenant (matches cPanel / Stancl naming).
     */
    public static function getDatabaseName( $tenant ): string {
        $data = is_array( $tenant->data ?? null )
            ? $tenant->data
            : ( json_decode( $tenant->data ?? '{}', true ) ?: [] );

        if ( ! empty( $data['tenancy_db_name'] ) ) {
            return $data['tenancy_db_name'];
        }

        $prefix = config( 'tenancy.database.prefix', 'affsellc_' );
        $suffix = config( 'tenancy.database.suffix', '' );

        return $prefix . $tenant->getTenantKey() . $suffix;
    }

    /**
     * Configure and return the dynamic connection name for a tenant database.
     */
    public static function connectionForTenant( $tenant ): string {
        $connectionName = self::getTenantConnectionName( $tenant );
        self::configureTenantConnection( $tenant, $connectionName );

        return $connectionName;
    }

    /**
     * Build a Laravel-style paginator array (with links) from an in-memory collection.
     */
    public static function paginateCollection( Collection $items, ?int $perPage = null ): array {
        $perPage  = $perPage ?? (int) request( 'per_page', 10 );
        $page     = max( 1, (int) request( 'page', 1 ) );
        $total    = $items->count();
        $lastPage = $total > 0 ? (int) ceil( $total / $perPage ) : 0;
        $offset   = ( $page - 1 ) * $perPage;
        $pageItems = $items->slice( $offset, $perPage );

        $path        = request()->url();
        $queryParams = request()->query();
        $buildUrl    = function ( $pageNum ) use ( $path, $queryParams ) {
            $queryParams['page'] = $pageNum;

            return $path . '?' . http_build_query( $queryParams );
        };

        $links   = [];
        $links[] = [
            'url'    => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'label'  => '&laquo; Previous',
            'active' => false,
        ];

        for ( $i = 1; $i <= $lastPage; $i++ ) {
            $links[] = [
                'url'    => $buildUrl( $i ),
                'label'  => (string) $i,
                'active' => $i === $page,
            ];
        }

        $links[] = [
            'url'    => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'label'  => 'Next &raquo;',
            'active' => false,
        ];

        return [
            'current_page'    => $page,
            'data'            => $pageItems->values(),
            'first_page_url'  => $buildUrl( 1 ),
            'from'            => $total > 0 ? $offset + 1 : null,
            'last_page'       => $lastPage,
            'last_page_url'   => $buildUrl( max( 1, $lastPage ) ),
            'links'           => $links,
            'next_page_url'   => $page < $lastPage ? $buildUrl( $page + 1 ) : null,
            'path'            => $path,
            'per_page'        => $perPage,
            'prev_page_url'   => $page > 1 ? $buildUrl( $page - 1 ) : null,
            'to'              => $total > 0 ? min( $offset + $perPage, $total ) : null,
            'total'           => $total,
        ];
    }

    /**
     * Get a single row from a tenant database without hydrating an Eloquent model.
     * This avoids implicit relationship loading during checkout/cart flows.
     *
     * @param string|int $tenantId
     * @param string $modelClass
     * @param callable|null $queryCallback
     * @return object|null
     */
    public static function getSingleRecordFromTenant(string|int $tenantId, string $modelClass, callable $queryCallback = null): ?object
    {
        $tenant = Tenant::on('mysql')->find($tenantId);

        if (!$tenant) {
            return null;
        }

        $originalConnection = DB::getDefaultConnection();

        try {
            $connectionName = self::getTenantConnectionName($tenant);
            self::configureTenantConnection($tenant, $connectionName);

            $model = new $modelClass;
            $query = DB::connection($connectionName)->table($model->getTable());

            if ($queryCallback) {
                $result = $queryCallback($query);
                if ($result !== null) {
                    $query = $result;
                }
            }

            $record = $query->first();

            if (!$record) {
                return null;
            }

            foreach ($model->getCasts() as $attribute => $cast) {
                if (!property_exists($record, $attribute)) {
                    continue;
                }

                if (!is_string($record->{$attribute})) {
                    continue;
                }

                if (in_array($cast, ['array', 'json', 'object', 'collection'], true)) {
                    $decoded = json_decode($record->{$attribute}, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $record->{$attribute} = $decoded;
                    }
                }
            }

            $domain = $tenant->domains()->first();
            $record->tenant_id = $tenant->id;
            $record->tenant_domain = $domain?->domain;
            $record->tenant_name = $tenant->company_name;

            return $record;
        } catch (\Exception $e) {
            \Log::error("Failed to query single record from tenant {$tenantId}: " . $e->getMessage());
            return null;
        } finally {
            DB::setDefaultConnection($originalConnection);
            config(['database.default' => $originalConnection]);
        }
    }

    /**
     * Load a {@see User} from a tenant database by primary key (admin / cross-tenant reads).
     * Does not fall back to central users.
     */
    public static function getTenantUserById( string|int $tenantId, int $userId ): ?User {
        $tenant = Tenant::on( 'mysql' )->find( $tenantId );

        if ( ! $tenant ) {
            return null;
        }

        $originalConnection = DB::getDefaultConnection();

        try {
            $connectionName = self::getTenantConnectionName( $tenant );
            self::configureTenantConnection( $tenant, $connectionName );

            return User::on( $connectionName )->withoutGlobalScopes()->whereKey( $userId )->first();
        } catch ( \Exception $e ) {
            \Log::error( "getTenantUserById failed for tenant {$tenantId}: " . $e->getMessage() );

            return null;
        } finally {
            DB::setDefaultConnection( $originalConnection );
            config( ['database.default' => $originalConnection] );
        }
    }
}
