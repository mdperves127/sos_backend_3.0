<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'shipping_box_sizes', function ( Blueprint $table ) {
            $table->id();
            $table->string( 'name' )->nullable();
            $table->decimal( 'length', 12, 2 )->default( 0 );
            $table->decimal( 'width', 12, 2 )->default( 0 );
            $table->decimal( 'height', 12, 2 )->default( 0 );
            $table->string( 'measurement_type', 20 )->default( 'in' ); // in, cm, mm, m, ft
            $table->decimal( 'weight', 12, 2 )->default( 0 );
            $table->string( 'weight_type', 20 )->default( 'lb' ); // lb, kg, g, oz
            $table->string( 'status' )->default( 'active' )->nullable();
            $table->timestamps();
            $table->softDeletes();
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'shipping_box_sizes' );
    }
};
