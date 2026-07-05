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
        Schema::create( 'tenant_coupon_usages', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'tenant_coupon_id' );
            $table->unsignedBigInteger( 'order_id' )->nullable();
            $table->unsignedBigInteger( 'user_id' )->nullable();
            $table->string( 'guest_email' )->nullable();
            $table->decimal( 'discount_amount', 12, 2 )->default( 0 );
            $table->timestamps();

            $table->index( ['tenant_coupon_id', 'user_id'] );
            $table->index( ['tenant_coupon_id', 'guest_email'] );
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists( 'tenant_coupon_usages' );
    }
};
