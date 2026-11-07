<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'orders', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'orders', 'form_data' ) ) {
                $table->json( 'form_data' )->nullable()->after( 'additional_note' );
            }
            if ( ! Schema::hasColumn( 'orders', 'payment_type' ) ) {
                $table->string( 'payment_type', 32 )->nullable()->after( 'form_data' );
            }
        } );

        Schema::table( 'order_details', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'order_details', 'variant_id' ) ) {
                $table->unsignedBigInteger( 'variant_id' )->nullable()->after( 'product_id' );
            }
            if ( ! Schema::hasColumn( 'order_details', 'variant_label' ) ) {
                $table->string( 'variant_label', 255 )->nullable()->after( 'variant_id' );
            }
            if ( ! Schema::hasColumn( 'order_details', 'variant_options' ) ) {
                $table->json( 'variant_options' )->nullable()->after( 'variant_label' );
            }
            if ( ! Schema::hasColumn( 'order_details', 'product_name' ) ) {
                $table->string( 'product_name', 255 )->nullable()->after( 'variant_options' );
            }
            if ( ! Schema::hasColumn( 'order_details', 'sku' ) ) {
                $table->string( 'sku', 128 )->nullable()->after( 'product_name' );
            }
            if ( ! Schema::hasColumn( 'order_details', 'image' ) ) {
                $table->string( 'image', 500 )->nullable()->after( 'sku' );
            }
        } );
    }

    public function down(): void
    {
        Schema::table( 'orders', function ( Blueprint $table ) {
            foreach ( ['form_data', 'payment_type'] as $column ) {
                if ( Schema::hasColumn( 'orders', $column ) ) {
                    $table->dropColumn( $column );
                }
            }
        } );

        Schema::table( 'order_details', function ( Blueprint $table ) {
            foreach ( ['variant_id', 'variant_label', 'variant_options', 'product_name', 'sku', 'image'] as $column ) {
                if ( Schema::hasColumn( 'order_details', $column ) ) {
                    $table->dropColumn( $column );
                }
            }
        } );
    }
};
