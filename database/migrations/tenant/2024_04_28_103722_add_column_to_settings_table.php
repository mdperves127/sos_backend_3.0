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
        Schema::table( 'settings', function ( Blueprint $table ) {
            $table->text( 'vendor_text' )->nullable();
            $table->text( 'affiliate_text' )->nullable();
            $table->decimal( 'extra_charge' )->nullable();
            $table->enum( 'extra_charge_status', ['on', 'off'] )->nullable();
            $table->decimal( 'minimum_withdraw' )->nullable();
            $table->decimal( 'withdraw_charge' )->nullable();
            $table->enum( 'withdraw_charge_status', ['on', 'off'] )->nullable();
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::table( 'settings', function ( Blueprint $table ) {
            $table->dropColumn( 'vendor_text' );
            $table->dropColumn( 'affiliate_text' );
            $table->dropColumn( 'extra_charge' );
            $table->dropColumn( 'extra_charge_status' );
            $table->dropColumn( 'minimum_withdraw' );
            $table->dropColumn( 'withdraw_charge' );
            $table->dropColumn( 'withdraw_charge_status' );
        } );
    }
};
