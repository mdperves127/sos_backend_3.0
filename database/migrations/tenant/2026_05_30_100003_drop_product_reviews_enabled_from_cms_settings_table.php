<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'cms_settings', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'cms_settings', 'product_reviews_enabled' ) ) {
                $table->dropColumn( 'product_reviews_enabled' );
            }
        } );
    }

    public function down(): void {
        Schema::table( 'cms_settings', function ( Blueprint $table ) {
            if ( !Schema::hasColumn( 'cms_settings', 'product_reviews_enabled' ) ) {
                $table->boolean( 'product_reviews_enabled' )->default( false )->after( 'theme' );
            }
        } );
    }
};
