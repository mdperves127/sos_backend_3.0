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
        Schema::create( 'woocommerce_credentials', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'vendor_id' )->nullable();
            $table->string( 'wc_key' )->nullable();
            $table->string( 'wc_secret' )->nullable();
            $table->string( 'wc_url' )->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign( 'vendor_id' )->references( 'id' )->on( 'users' )->onDelete( 'cascade' );
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::dropIfExists( 'woocommerce_credentials' );
    }
};
