<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if ( ! Schema::hasTable( 'product_bundles' ) ) {
            Schema::create( 'product_bundles', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger( 'category_id' )->index();
                $table->unsignedBigInteger( 'subcategory_id' )->nullable()->index();
                $table->string( 'name' );
                $table->decimal( 'bundle_price', 12, 2 );
                $table->string( 'status', 20 )->default( 'active' )->index();
                $table->timestamps();
            } );
        }

        if ( ! Schema::hasTable( 'product_bundle_items' ) ) {
            Schema::create( 'product_bundle_items', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger( 'bundle_id' )->index();
                $table->unsignedBigInteger( 'product_id' )->index();
                $table->unsignedInteger( 'quantity' )->default( 1 );
                $table->timestamps();

                $table->unique( ['bundle_id', 'product_id'] );
            } );
        }

        if ( Schema::hasTable( 'carts' ) && ! Schema::hasColumn( 'carts', 'bundle_id' ) ) {
            Schema::table( 'carts', function ( Blueprint $table ) {
                $table->unsignedBigInteger( 'bundle_id' )->nullable()->index()->after( 'product_id' );
            } );
        }

        if ( Schema::hasTable( 'pos_sales_details' ) && ! Schema::hasColumn( 'pos_sales_details', 'bundle_id' ) ) {
            Schema::table( 'pos_sales_details', function ( Blueprint $table ) {
                $table->unsignedBigInteger( 'bundle_id' )->nullable()->index()->after( 'product_id' );
                $table->string( 'bundle_name' )->nullable()->after( 'bundle_id' );
            } );
        }
    }

    public function down(): void
    {
        if ( Schema::hasTable( 'pos_sales_details' ) ) {
            Schema::table( 'pos_sales_details', function ( Blueprint $table ) {
                if ( Schema::hasColumn( 'pos_sales_details', 'bundle_name' ) ) {
                    $table->dropColumn( 'bundle_name' );
                }
                if ( Schema::hasColumn( 'pos_sales_details', 'bundle_id' ) ) {
                    $table->dropColumn( 'bundle_id' );
                }
            } );
        }

        if ( Schema::hasTable( 'carts' ) && Schema::hasColumn( 'carts', 'bundle_id' ) ) {
            Schema::table( 'carts', function ( Blueprint $table ) {
                $table->dropColumn( 'bundle_id' );
            } );
        }

        Schema::dropIfExists( 'product_bundle_items' );
        Schema::dropIfExists( 'product_bundles' );
    }
};
