<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table( 'products', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'products', 'is_private' ) ) {
                $table->boolean( 'is_private' )->default( false )->index();
            }
            if ( ! Schema::hasColumn( 'products', 'auto_approve_dropshipper' ) ) {
                // NULL = inherit the category setting.
                $table->boolean( 'auto_approve_dropshipper' )->nullable();
            }
            if ( ! Schema::hasColumn( 'products', 'allow_custom_selling_price' ) ) {
                $table->boolean( 'allow_custom_selling_price' )->default( true );
            }
            if ( ! Schema::hasColumn( 'products', 'dropshipper_price_mode' ) ) {
                $table->string( 'dropshipper_price_mode', 20 )->default( 'range' );
            }
            if ( ! Schema::hasColumn( 'products', 'dropshipper_min_price' ) ) {
                $table->decimal( 'dropshipper_min_price', 12, 2 )->nullable();
            }
            if ( ! Schema::hasColumn( 'products', 'dropshipper_max_price' ) ) {
                $table->decimal( 'dropshipper_max_price', 12, 2 )->nullable();
            }
            if ( ! Schema::hasColumn( 'products', 'dropshipper_fixed_price' ) ) {
                $table->decimal( 'dropshipper_fixed_price', 12, 2 )->nullable();
            }
        } );
    }

    public function down()
    {
        Schema::table( 'products', function ( Blueprint $table ) {
            foreach ( [
                'is_private',
                'auto_approve_dropshipper',
                'allow_custom_selling_price',
                'dropshipper_price_mode',
                'dropshipper_min_price',
                'dropshipper_max_price',
                'dropshipper_fixed_price',
            ] as $column ) {
                if ( Schema::hasColumn( 'products', $column ) ) {
                    if ( $column === 'is_private' ) {
                        $table->dropIndex( ['is_private'] );
                    }
                    $table->dropColumn( $column );
                }
            }
        } );
    }
};
