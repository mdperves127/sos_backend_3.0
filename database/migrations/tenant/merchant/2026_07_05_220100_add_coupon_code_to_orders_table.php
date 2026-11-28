<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table( 'orders', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'orders', 'coupon_code' ) ) {
                $table->string( 'coupon_code', 100 )->nullable()->after( 'sale_discount' );
            }
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table( 'orders', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'orders', 'coupon_code' ) ) {
                $table->dropColumn( 'coupon_code' );
            }
        } );
    }
};
