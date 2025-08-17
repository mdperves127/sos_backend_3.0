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
            $table->decimal( 'sale_discount' )->default( 0 );
            $table->decimal( 'paid_amount' )->default( 0 );
            $table->decimal( 'due_amount' )->default( 0 );
            $table->integer( 'custom_order' )->default( 0 );
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::table( 'orders', function ( Blueprint $table ) {
            //
        } );
    }
};
