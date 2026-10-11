<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table( 'settings', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'settings', 'dropship_fee_type' ) ) {
                // none | fixed | percentage | plan
                $table->string( 'dropship_fee_type', 20 )->default( 'none' );
            }
            if ( ! Schema::hasColumn( 'settings', 'dropship_fee_value' ) ) {
                $table->decimal( 'dropship_fee_value', 12, 2 )->default( 0 );
            }
        } );

        Schema::table( 'subscriptions', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'subscriptions', 'dropship_fee_type' ) ) {
                // fixed | percentage; NULL = plan charges no platform fee.
                $table->string( 'dropship_fee_type', 20 )->nullable();
            }
            if ( ! Schema::hasColumn( 'subscriptions', 'dropship_fee_value' ) ) {
                $table->decimal( 'dropship_fee_value', 12, 2 )->nullable();
            }
        } );
    }

    public function down()
    {
        Schema::table( 'settings', function ( Blueprint $table ) {
            foreach ( ['dropship_fee_type', 'dropship_fee_value'] as $column ) {
                if ( Schema::hasColumn( 'settings', $column ) ) {
                    $table->dropColumn( $column );
                }
            }
        } );

        Schema::table( 'subscriptions', function ( Blueprint $table ) {
            foreach ( ['dropship_fee_type', 'dropship_fee_value'] as $column ) {
                if ( Schema::hasColumn( 'subscriptions', $column ) ) {
                    $table->dropColumn( $column );
                }
            }
        } );
    }
};
