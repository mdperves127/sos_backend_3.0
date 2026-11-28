<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if ( Schema::hasTable( 'cms_settings' ) && ! Schema::hasColumn( 'cms_settings', 'is_demo_active' ) ) {
            Schema::table( 'cms_settings', function ( Blueprint $table ) {
                $table->boolean( 'is_demo_active' )->default( false )->after( 'id' );
            } );
        }

        // Remove from legacy settings table if the earlier migration already ran.
        if ( Schema::hasTable( 'settings' ) && Schema::hasColumn( 'settings', 'is_demo_active' ) ) {
            Schema::table( 'settings', function ( Blueprint $table ) {
                $table->dropColumn( 'is_demo_active' );
            } );
        }
    }

    public function down(): void {
        if ( Schema::hasTable( 'cms_settings' ) && Schema::hasColumn( 'cms_settings', 'is_demo_active' ) ) {
            Schema::table( 'cms_settings', function ( Blueprint $table ) {
                $table->dropColumn( 'is_demo_active' );
            } );
        }

        if ( Schema::hasTable( 'settings' ) && ! Schema::hasColumn( 'settings', 'is_demo_active' ) ) {
            Schema::table( 'settings', function ( Blueprint $table ) {
                $table->boolean( 'is_demo_active' )->default( false )->after( 'id' );
            } );
        }
    }
};
