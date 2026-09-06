<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central vendor_roles may still exist for legacy vendor panel.
 * Tenant copy of edit_order is in tenant/2026_09_06_233000_*.
 */
return new class extends Migration {
    public function up(): void
    {
        if ( Schema::hasTable( 'vendor_roles' ) && ! Schema::hasColumn( 'vendor_roles', 'edit_order' ) ) {
            Schema::table( 'vendor_roles', function ( Blueprint $table ) {
                $table->integer( 'edit_order' )->nullable();
            } );
        }
    }

    public function down(): void
    {
        if ( Schema::hasTable( 'vendor_roles' ) && Schema::hasColumn( 'vendor_roles', 'edit_order' ) ) {
            Schema::table( 'vendor_roles', function ( Blueprint $table ) {
                $table->dropColumn( 'edit_order' );
            } );
        }
    }
};
