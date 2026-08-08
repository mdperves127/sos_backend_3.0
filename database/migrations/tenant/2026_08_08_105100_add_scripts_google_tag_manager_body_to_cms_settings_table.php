<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'cms_settings', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'cms_settings', 'scripts_google_tag_manager_body' ) ) {
                $table->longText( 'scripts_google_tag_manager_body' )->nullable()->after( 'scripts_google_tag_manager' );
            }
        } );
    }

    public function down(): void
    {
        Schema::table( 'cms_settings', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'cms_settings', 'scripts_google_tag_manager_body' ) ) {
                $table->dropColumn( 'scripts_google_tag_manager_body' );
            }
        } );
    }
};
