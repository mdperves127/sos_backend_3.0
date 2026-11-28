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
        Schema::table( 'orders', function ( Blueprint $table ) {
            $table->text( 'delivery_area' )->nullable();
            $table->text( 'pickup_area' )->nullable();
            $table->string( 'shipping_date' )->nullable();
            $table->text( 'additional_note' )->nullable();
            $table->text( 'internal_note' )->nullable();
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::table( 'orders', function ( Blueprint $table ) {
            $table->dropColumn( 'delivery_area' );
            $table->dropColumn( 'pickup_area' );
            $table->dropColumn( 'shipping_date' );
            $table->dropColumn( 'additional_note' );
            $table->dropColumn( 'internal_note' );
        } );
    }
};
