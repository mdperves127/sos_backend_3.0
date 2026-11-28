<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'products', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'products', 'is_stock_show' ) ) {
                $column = $table->unsignedTinyInteger( 'is_stock_show' )->default( 1 );

                if ( Schema::hasColumn( 'products', 'is_show_website' ) ) {
                    $column->after( 'is_show_website' );
                } elseif ( Schema::hasColumn( 'products', 'status' ) ) {
                    $column->after( 'status' );
                }
            }
        } );

        if ( Schema::hasColumn( 'products', 'is_stock_show' ) ) {
            DB::table( 'products' )->whereNull( 'is_stock_show' )->update( ['is_stock_show' => 1] );
        }
    }

    public function down(): void {
        Schema::table( 'products', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'products', 'is_stock_show' ) ) {
                $table->dropColumn( 'is_stock_show' );
            }
        } );
    }
};
