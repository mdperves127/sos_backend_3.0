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
        Schema::table( 'vendor_roles', function ( Blueprint $table ) {
            $table->integer( 'delivery_area' )->nullable();
            $table->integer( 'pickup_area' )->nullable();
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::table( 'vendor_roles', function ( Blueprint $table ) {
            $table->dropColumn( 'delivery_area' );
            $table->dropColumn( 'pickup_area' );
        } );
    }
};
