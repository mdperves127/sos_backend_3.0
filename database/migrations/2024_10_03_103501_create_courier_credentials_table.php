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
        Schema::create( 'courier_credentials', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'vendor_id' );
            $table->string( 'courier_name' )->nullable();
            $table->string( 'api_key' )->comment( 'api key / access key / client id' );
            $table->string( 'secret_key' )->comment( 'secret key / access secret / client secret' );
            $table->string( 'client_email' )->nullable();
            $table->string( 'client_password' )->nullable();
            $table->string( 'store_id' )->nullable();
            $table->enum( 'default', ['yes', 'no'] )->default( 'no' );
            $table->enum( 'status', ['active', 'deactive'] )->default( 'active' );
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
        Schema::dropIfExists( 'courier_credentials' );
    }
};
