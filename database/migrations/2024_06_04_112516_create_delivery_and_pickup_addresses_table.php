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
        Schema::create( 'delivery_and_pickup_addresses', function ( Blueprint $table ) {
            $table->id();
            $table->integer( 'user_id' );
            $table->unsignedBigInteger( 'vendor_id' );
            $table->text( 'address' );
            $table->enum( 'type', ['delivery', 'pickup'] )->nullable();
            $table->enum( 'status', ['active', 'deactive'] )->nullable();
            $table->softDeletes();
            $table->timestamps();
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::dropIfExists( 'delivery_and_pickup_addresses' );
    }
};
