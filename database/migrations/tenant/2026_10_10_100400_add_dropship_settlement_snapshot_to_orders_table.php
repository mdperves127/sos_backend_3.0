<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'dropshipper_base_price',
        'dropshipper_selling_price',
        'platform_fee_type',
        'platform_fee_rate',
        'platform_fee_source',
        'platform_fee_amount',
        'dropshipper_gross_amount',
        'dropshipper_net_amount',
        'platform_fee_settled_at',
    ];

    public function up()
    {
        Schema::table( 'orders', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'orders', 'dropshipper_base_price' ) ) {
                $table->decimal( 'dropshipper_base_price', 12, 2 )->nullable();
            }
            if ( ! Schema::hasColumn( 'orders', 'dropshipper_selling_price' ) ) {
                $table->decimal( 'dropshipper_selling_price', 12, 2 )->nullable();
            }
            if ( ! Schema::hasColumn( 'orders', 'platform_fee_type' ) ) {
                $table->string( 'platform_fee_type', 20 )->nullable();
            }
            if ( ! Schema::hasColumn( 'orders', 'platform_fee_rate' ) ) {
                $table->decimal( 'platform_fee_rate', 12, 2 )->nullable();
            }
            if ( ! Schema::hasColumn( 'orders', 'platform_fee_source' ) ) {
                $table->string( 'platform_fee_source', 50 )->nullable();
            }
            if ( ! Schema::hasColumn( 'orders', 'platform_fee_amount' ) ) {
                $table->decimal( 'platform_fee_amount', 12, 2 )->default( 0 );
            }
            if ( ! Schema::hasColumn( 'orders', 'dropshipper_gross_amount' ) ) {
                $table->decimal( 'dropshipper_gross_amount', 12, 2 )->nullable();
            }
            if ( ! Schema::hasColumn( 'orders', 'dropshipper_net_amount' ) ) {
                $table->decimal( 'dropshipper_net_amount', 12, 2 )->nullable();
            }
            if ( ! Schema::hasColumn( 'orders', 'platform_fee_settled_at' ) ) {
                $table->timestamp( 'platform_fee_settled_at' )->nullable();
            }
        } );
    }

    public function down()
    {
        Schema::table( 'orders', function ( Blueprint $table ) {
            foreach ( self::COLUMNS as $column ) {
                if ( Schema::hasColumn( 'orders', $column ) ) {
                    $table->dropColumn( $column );
                }
            }
        } );
    }
};
