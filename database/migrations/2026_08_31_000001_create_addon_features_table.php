<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'addon_features', function ( Blueprint $table ) {
            $table->id();
            $table->foreignId( 'addon_id' )->constrained( 'addons' )->cascadeOnDelete();
            $table->string( 'key' );
            $table->string( 'value' );
            $table->enum( 'visibility', ['private', 'public'] )->default( 'public' );
            $table->timestamps();

            $table->unique( ['addon_id', 'key'] );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'addon_features' );
    }
};
