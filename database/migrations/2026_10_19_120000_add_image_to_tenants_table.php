<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop photo for the tenant (central tenants table).
 */
return new class extends Migration {
    public function up(): void
    {
        if ( ! Schema::hasTable( 'tenants' ) ) {
            return;
        }

        Schema::table( 'tenants', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'tenants', 'image' ) ) {
                $table->string( 'image' )->nullable()->after( 'address' );
            }
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'tenants' ) ) {
            return;
        }

        Schema::table( 'tenants', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'tenants', 'image' ) ) {
                $table->dropColumn( 'image' );
            }
        } );
    }
};
