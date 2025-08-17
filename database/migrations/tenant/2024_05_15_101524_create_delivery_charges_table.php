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
        Schema::create( 'delivery_charges', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'user_id' );
            $table->unsignedBigInteger( 'vendor_id' );
            $table->string( 'area' );
            $table->decimal( 'charge' )->default( 0 );
            $table->enum( 'status', ['active', 'deactive'] )->default( 'active' );
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
        Schema::dropIfExists( 'delivery_charges' );
    }
};
