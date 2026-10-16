<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single JSON blob for the Header & Footer Builder (active template + config).
 */
return new class extends Migration {
    public function up(): void
    {
        if ( ! Schema::hasTable( 'cms_settings' ) ) {
            return;
        }

        Schema::table( 'cms_settings', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'cms_settings', 'header_footer' ) ) {
                $table->json( 'header_footer' )->nullable();
            }
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'cms_settings' ) ) {
            return;
        }

        Schema::table( 'cms_settings', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'cms_settings', 'header_footer' ) ) {
                $table->dropColumn( 'header_footer' );
            }
        } );
    }
};
