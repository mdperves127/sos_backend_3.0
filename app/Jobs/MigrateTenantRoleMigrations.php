<?php

namespace App\Jobs;

use App\Services\TenantMigrationPathResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Runs role-scoped tenant migrations AFTER the shared Stancl MigrateDatabase job.
 * Existing shared migrations stay in database/migrations/tenant (unchanged paths).
 */
class MigrateTenantRoleMigrations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected TenantWithDatabase $tenant;

    public function __construct( TenantWithDatabase $tenant )
    {
        $this->tenant = $tenant;
    }

    public function handle(): void
    {
        $type  = is_string( $this->tenant->type ?? null ) ? $this->tenant->type : null;
        $paths = TenantMigrationPathResolver::rolePathsForType( $type );

        if ( $paths === [] ) {
            return;
        }

        if ( ! tenancy()->initialized || (string) tenant( 'id' ) !== (string) $this->tenant->getTenantKey() ) {
            tenancy()->initialize( $this->tenant );
        }

        foreach ( $paths as $path ) {
            $files = glob( rtrim( $path, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . '*_*.php' ) ?: [];
            if ( $files === [] ) {
                continue;
            }

            Artisan::call( 'migrate', [
                '--path'  => $this->relativePath( $path ),
                '--force' => true,
            ] );
        }
    }

    private function relativePath( string $absolute ): string
    {
        $base = rtrim( str_replace( '\\', '/', base_path() ), '/' ) . '/';
        $path = str_replace( '\\', '/', $absolute );

        if ( str_starts_with( $path, $base ) ) {
            return substr( $path, strlen( $base ) );
        }

        return $absolute;
    }
}
