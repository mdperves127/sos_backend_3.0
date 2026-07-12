<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if ( Schema::hasTable( 'settings' ) && Schema::hasColumn( 'settings', 'is_stock_show' ) ) {
            Schema::table( 'settings', function ( Blueprint $table ) {
                $table->dropColumn( 'is_stock_show' );
            } );
        }
    }

    public function down(): void {
        if ( Schema::hasTable( 'settings' ) ) {
            Schema::table( 'settings', function ( Blueprint $table ) {
                if ( ! Schema::hasColumn( 'settings', 'is_stock_show' ) ) {
                    $table->enum( 'is_stock_show', ['yes', 'no'] )->default( 'yes' );
                }
            } );
        }
    }
};
