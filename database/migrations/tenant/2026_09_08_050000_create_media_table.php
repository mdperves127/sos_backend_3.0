<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant media library (images/videos/pdf) for merchant uploads.
 */
return new class extends Migration {
    public function up(): void
    {
        if ( Schema::hasTable( 'media' ) ) {
            return;
        }

        Schema::create( 'media', function ( Blueprint $table ) {
            $table->id();
            $table->unsignedBigInteger( 'user_id' )->nullable()->index();
            $table->string( 'file_name' );
            $table->string( 'original_name' );
            $table->string( 'mime_type', 100 )->index();
            $table->string( 'extension', 20 );
            $table->unsignedBigInteger( 'size' )->default( 0 );
            $table->string( 'path' );
            $table->timestamps();

            $table->index( ['original_name'] );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'media' );
    }
};
