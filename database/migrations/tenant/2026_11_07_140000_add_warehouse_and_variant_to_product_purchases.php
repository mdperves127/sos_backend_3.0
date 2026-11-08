<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'product_purchases', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'product_purchases', 'warehouse_id' ) ) {
                $table->unsignedBigInteger( 'warehouse_id' )->nullable()->after( 'supplier_id' );
            }
        } );

        Schema::table( 'product_purchase_details', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'product_purchase_details', 'variant_id' ) ) {
                $table->unsignedBigInteger( 'variant_id' )->nullable()->after( 'product_id' );
            }
        } );
    }

    public function down(): void
    {
        Schema::table( 'product_purchases', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'product_purchases', 'warehouse_id' ) ) {
                $table->dropColumn( 'warehouse_id' );
            }
        } );

        Schema::table( 'product_purchase_details', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'product_purchase_details', 'variant_id' ) ) {
                $table->dropColumn( 'variant_id' );
            }
        } );
    }
};
