<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantMigrationPathResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Migrate tenant DBs using shared path + optional role path (merchant|dropshipper).
 *
 * Safe for existing installs: does not rename/move historical migrations.
 */
class MigrateTenantsStructuredCommand extends Command
{
    protected $signature = 'tenants:migrate-structured
        {--tenants=* : Optional tenant IDs}
        {--type= : Limit to merchant or dropshipper}
        {--shared-only : Only run shared tenant migrations}
        {--role-only : Only run role-scoped migrations}
        {--pretend : Dump SQL without running}';

    protected $description = 'Run shared (+ role-scoped) tenant migrations without moving historical files';

    public function handle(): int
    {
        $typeFilter = strtolower( trim( (string) $this->option( 'type' ) ) );
        if ( $typeFilter !== '' && ! in_array( $typeFilter, ['merchant', 'dropshipper'], true ) ) {
            $this->error( '--type must be merchant or dropshipper' );

            return self::FAILURE;
        }

        $query = Tenant::on( 'mysql' )->orderBy( 'id' );
        if ( $typeFilter !== '' ) {
            $query->where( 'type', $typeFilter );
        }

        $ids = array_filter( (array) $this->option( 'tenants' ) );
        if ( $ids !== [] ) {
            $query->whereIn( 'id', $ids );
        }

        $tenants = $query->get();
        if ( $tenants->isEmpty() ) {
            $this->warn( 'No tenants matched.' );

            return self::SUCCESS;
        }

        $sharedOnly = (bool) $this->option( 'shared-only' );
        $roleOnly   = (bool) $this->option( 'role-only' );
        $pretend    = (bool) $this->option( 'pretend' );

        foreach ( $tenants as $tenant ) {
            $this->line( "→ {$tenant->id} ({$tenant->type})" );

            try {
                tenancy()->initialize( $tenant );

                if ( ! $roleOnly ) {
                    foreach ( TenantMigrationPathResolver::sharedPaths() as $path ) {
                        $this->migratePath( $path, $pretend );
                    }
                }

                if ( ! $sharedOnly ) {
                    foreach ( TenantMigrationPathResolver::rolePathsForType( $tenant->type ) as $path ) {
                        $this->migratePath( $path, $pretend );
                    }
                }
            } catch ( \Throwable $e ) {
                $this->error( "  failed: {$e->getMessage()}" );
            } finally {
                if ( tenancy()->initialized ) {
                    tenancy()->end();
                }
            }
        }

        $this->info( 'Done.' );

        return self::SUCCESS;
    }

    private function migratePath( string $absolutePath, bool $pretend ): void
    {
        $files = glob( rtrim( $absolutePath, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '*_*.php' ) ?: [];
        if ( $files === [] && ! str_ends_with( $absolutePath, 'tenant' ) ) {
            // Empty role folder — skip quietly.
            return;
        }

        $relative = $this->relativePath( $absolutePath );
        $this->comment( "  migrate --path={$relative}" );

        $params = [
            '--path'  => $relative,
            '--force' => true,
        ];
        if ( $pretend ) {
            $params['--pretend'] = true;
        }

        Artisan::call( 'migrate', $params );
        $this->output->write( Artisan::output() );
    }

    private function relativePath( string $absolute ): string
    {
        $base = rtrim( str_replace( '\\', '/', base_path() ), '/' ) . '/';
        $path = str_replace( '\\', '/', $absolute );

        return str_starts_with( $path, $base ) ? substr( $path, strlen( $base ) ) : $absolute;
    }
}
