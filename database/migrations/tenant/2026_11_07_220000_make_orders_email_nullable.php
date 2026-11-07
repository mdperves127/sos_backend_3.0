<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'orders', function ( Blueprint $table ) {
            $table->string( 'email' )->nullable()->change();
            $table->string( 'city' )->nullable()->change();
        } );
    }

    public function down(): void
    {
        Schema::table( 'orders', function ( Blueprint $table ) {
            $table->string( 'email' )->nullable( false )->change();
            $table->string( 'city' )->nullable( false )->change();
        } );
    }
};
