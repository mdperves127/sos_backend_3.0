<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical filename kept for tenants that already ran this migration.
 * New installs: prefer cms_settings via 2026_11_28_160000_*.
 * If this runs first on a fresh DB, add to cms_settings (not settings).
 */
return new class extends Migration {
    public function up(): void {
        if ( Schema::hasTable( 'cms_settings' ) && ! Schema::hasColumn( 'cms_settings', 'is_demo_active' ) ) {
            Schema::table( 'cms_settings', function ( Blueprint $table ) {
                $table->boolean( 'is_demo_active' )->default( false )->after( 'id' );
            } );
        }
    }

    public function down(): void {
        // Column removal is handled by 2026_11_28_160000 when rolling back that migration.
        // Only drop here if the later migration was never applied.
        if (
            Schema::hasTable( 'cms_settings' )
            && Schema::hasColumn( 'cms_settings', 'is_demo_active' )
            && ! $this->laterMigrationApplied()
        ) {
            Schema::table( 'cms_settings', function ( Blueprint $table ) {
                $table->dropColumn( 'is_demo_active' );
            } );
        }
    }

    private function laterMigrationApplied(): bool {
        try {
            return \Illuminate\Support\Facades\DB::table( 'migrations' )
                ->where( 'migration', 'like', '%2026_11_28_160000_move_is_demo_active_to_cms_settings_table%' )
                ->exists();
        } catch ( \Throwable $e ) {
            return false;
        }
    }
};
