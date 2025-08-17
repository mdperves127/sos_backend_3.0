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
            $table->integer( 'order_return' )->nullable();
            $table->integer( 'order_ready' )->nullable();
            $table->integer( 'order_processing' )->nullable();
            $table->integer( 'damage_list' )->nullable();
            $table->integer( 'create_damage' )->nullable();
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::table( 'vendor_roles', function ( Blueprint $table ) {
            $table->dropColumn( 'order_return' );
            $table->dropColumn( 'order_ready' );
            $table->dropColumn( 'order_processing' );
            $table->dropColumn( 'damage_list' );
            $table->dropColumn( 'create_damage' );
        } );
    }
};
