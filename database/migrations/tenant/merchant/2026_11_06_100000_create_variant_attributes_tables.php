<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'variant_attributes', function ( Blueprint $table ) {
            $table->id();
            $table->string( 'name' );
            $table->string( 'slug' )->nullable();
            $table->string( 'status' )->default( 'active' )->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index( 'name' );
        } );

        Schema::create( 'variant_attribute_values', function ( Blueprint $table ) {
            $table->id();
            $table->foreignId( 'variant_attribute_id' )
                ->constrained( 'variant_attributes' )
                ->cascadeOnDelete();
            $table->string( 'value' );
            $table->unsignedInteger( 'sort_order' )->default( 0 );
            $table->timestamps();
            $table->softDeletes();

            $table->index( ['variant_attribute_id', 'value'], 'variant_attr_value_index' );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'variant_attribute_values' );
        Schema::dropIfExists( 'variant_attributes' );
    }
};
