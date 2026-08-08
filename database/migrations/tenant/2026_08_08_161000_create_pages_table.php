<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'pages', function ( Blueprint $table ) {
            $table->id();
            $table->string( 'page_name' );
            $table->string( 'page_url' )->unique();
            $table->longText( 'page_content' );
            $table->softDeletes();
            $table->timestamps();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'pages' );
    }
};
