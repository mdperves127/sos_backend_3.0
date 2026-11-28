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
        Schema::create( 'damages', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'product_id' )->nullable();
            $table->integer( 'user_id' )->nullable();
            $table->integer( 'vendor_id' )->nullable();
            $table->integer( 'qty' )->nullable();
            $table->text( 'note' )->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->foreign( 'product_id' )->references( 'id' )->on( 'products' )->onDelete( 'cascade' );
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::dropIfExists( 'damages' );
    }
};
