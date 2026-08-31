<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'addons', function ( Blueprint $table ) {
            $table->id();
            $table->string( 'name' );
            $table->string( 'photo' )->nullable();
            $table->enum( 'addon_type', ['membership', 'system'] );
            $table->decimal( 'price', 12, 2 )->default( 0 );
            $table->enum( 'for_tenant', ['dropshipper', 'merchant'] );
            $table->string( 'short_description' )->nullable();
            $table->text( 'description' )->nullable();
            $table->softDeletes();
            $table->timestamps();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'addons' );
    }
};
