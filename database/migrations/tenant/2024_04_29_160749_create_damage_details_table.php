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
        Schema::create( 'damage_details', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'damage_id' )->nullable();
            $table->integer( 'unit_id' )->nullable();
            $table->integer( 'size_id' )->nullable();
            $table->integer( 'color_id' )->nullable();
            $table->integer( 'sale_qty' )->default( 0 );
            $table->integer( 'damage_qty' )->default( 0 );
            $table->decimal( 'rate' )->default( 0 );
            $table->decimal( 'sub_total' )->default( 0 );
            $table->string( 'remark' )->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->foreign( 'damage_id' )->references( 'id' )->on( 'damages' )->onDelete( 'cascade' );
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::dropIfExists( 'damage_details' );
    }
};
