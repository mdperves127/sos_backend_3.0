<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        Schema::create( 'order_delivery_to_couriers', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'order_id' );
            $table->unsignedBigInteger( 'vendor_id' );
            $table->unsignedBigInteger( 'affiliator_id' )->nullable();
            $table->integer( 'courier_id' )->nullable();
            $table->string( 'merchant_order_id' );
            $table->string( 'recipient_name' );
            $table->string( 'recipient_phone' );
            $table->string( 'recipient_address' );
            $table->integer( 'recipient_city' )->nullable();
            $table->integer( 'recipient_zone' )->nullable();
            $table->integer( 'recipient_area' )->nullable();
            $table->string( 'delivery_type' );
            $table->string( 'item_type' );
            $table->text( 'special_instruction' );
            $table->integer( 'item_quantity' );
            $table->integer( 'item_weight' );
            $table->integer( 'amount_to_collect' );
            $table->text( 'item_description' );
            $table->softDeletes();
            $table->timestamps();
            $table->foreign( 'order_id' )->references( 'id' )->on( 'orders' )->onDelete( 'cascade' );
            $table->foreign( 'vendor_id' )->references( 'id' )->on( 'users' )->onDelete( 'cascade' );
            $table->foreign( 'affiliator_id' )->references( 'id' )->on( 'users' )->onDelete( 'cascade' );
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::dropIfExists( 'order_delivery_to_couriers' );
    }
};
