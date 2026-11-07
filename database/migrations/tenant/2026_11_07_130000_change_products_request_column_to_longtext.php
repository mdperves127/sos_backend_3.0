<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tab meta (shipping, variant_attributes, has_variants) is stored in products.request.
     * string/varchar(255) truncates the JSON and shipping disappears on edit.
     */
    public function up(): void
    {
        Schema::table( 'products', function ( Blueprint $table ) {
            $table->longText( 'request' )->nullable()->change();
        } );
    }

    public function down(): void
    {
        Schema::table( 'products', function ( Blueprint $table ) {
            $table->string( 'request' )->nullable()->change();
        } );
    }
};
