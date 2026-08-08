<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ( ! Schema::hasTable( 'pos_sales' ) || Schema::hasColumn( 'pos_sales', 'discount_type' ) ) {
            return;
        }

        Schema::table( 'pos_sales', function ( Blueprint $table ) {
            $table->enum( 'discount_type', ['flat', 'percentage'] )->default( 'flat' )->after( 'sale_discount' );
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'pos_sales' ) || ! Schema::hasColumn( 'pos_sales', 'discount_type' ) ) {
            return;
        }

        Schema::table( 'pos_sales', function ( Blueprint $table ) {
            $table->dropColumn( 'discount_type' );
        } );
    }
};
