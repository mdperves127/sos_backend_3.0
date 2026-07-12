<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'cms_settings', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'cms_settings', 'is_stock_show' ) ) {
                $table->enum( 'is_stock_show', ['yes', 'no'] )->default( 'yes' );
            }
        } );
    }

    public function down(): void {
        Schema::table( 'cms_settings', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'cms_settings', 'is_stock_show' ) ) {
                $table->dropColumn( 'is_stock_show' );
            }
        } );
    }
};
