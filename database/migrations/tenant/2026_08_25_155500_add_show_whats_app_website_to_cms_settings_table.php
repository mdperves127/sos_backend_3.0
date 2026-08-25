<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'cms_settings', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'cms_settings', 'show_whats_app_website' ) ) {
                $table->enum( 'show_whats_app_website', ['yes', 'no'] )->default( 'yes' );
            }
        } );
    }

    public function down(): void {
        Schema::table( 'cms_settings', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'cms_settings', 'show_whats_app_website' ) ) {
                $table->dropColumn( 'show_whats_app_website' );
            }
        } );
    }
};
