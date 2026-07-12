<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'settings', function ( Blueprint $table ) {
            $table->enum( 'is_stock_show', ['yes', 'no'] )->default( 'yes' )->after( 'add_product_tutorial' );
        } );
    }

    public function down(): void
    {
        Schema::table( 'settings', function ( Blueprint $table ) {
            $table->dropColumn( 'is_stock_show' );
        } );
    }
};
