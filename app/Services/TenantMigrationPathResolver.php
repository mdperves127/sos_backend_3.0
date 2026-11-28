<?php

namespace App\Services;

use App\Models\Tenant;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * Resolves shared + role-scoped tenant migration paths from config.
 */
class TenantMigrationPathResolver
{
    /**
     * @return list<string>
     */
    public static function sharedPaths(): array
    {
        $path = (string) config( 'tenant_migrations.paths.shared', database_path( 'migrations/tenant' ) );

        return self::existingDirectories( [$path] );
    }

    /**
     * Role-scoped paths for a tenant type (merchant|dropshipper). Empty if unknown.
     *
     * @return list<string>
     */
    public static function rolePathsForType( ?string $type ): array
    {
        $type = strtolower( trim( (string) $type ) );
        $keys = (array) config( "tenant_migrations.role_paths.{$type}", [] );
        $paths = [];

        foreach ( $keys as $key ) {
            $path = config( "tenant_migrations.paths.{$key}" );
            if ( is_string( $path ) && $path !== '' ) {
                $paths[] = $path;
            }
        }

        return self::existingDirectories( $paths );
    }

    /**
     * Shared + role paths for one tenant.
     *
     * @return list<string>
     */
    public static function pathsForTenant( TenantContract|Tenant|null $tenant ): array
    {
        $type = null;
        if ( $tenant ) {
            $type = $tenant->type
                ?? ( $tenant->getAttribute( 'type' ) ?? null )
                ?? ( is_array( $tenant->data ?? null ) ? ( $tenant->data['type'] ?? null ) : null );
        }

        return array_values( array_unique( array_merge(
            self::sharedPaths(),
            self::rolePathsForType( is_string( $type ) ? $type : null )
        ) ) );
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function existingDirectories( array $paths ): array
    {
        $out = [];
        foreach ( $paths as $path ) {
            if ( is_string( $path ) && $path !== '' && is_dir( $path ) ) {
                $out[] = $path;
            }
        }

        return array_values( array_unique( $out ) );
    }
}
