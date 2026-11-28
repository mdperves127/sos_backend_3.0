<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product pre-order config (tenant DB). Does not duplicate product/order/customer data.
 */
return new class extends Migration {
    public function up(): void
    {
        if ( ! Schema::hasTable( 'product_pre_orders' ) ) {
            Schema::create( 'product_pre_orders', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger( 'product_id' )->unique();
                $table->date( 'expected_delivery_date' )->nullable()->index();
                $table->unsignedInteger( 'quantity_limit' )->default( 0 );
                $table->unsignedInteger( 'quantity_ordered' )->default( 0 );
                $table->decimal( 'advance_amount', 12, 2 )->default( 0 );
                // advance | full | both
                $table->string( 'payment_options', 20 )->default( 'both' );
                // active | closed
                $table->string( 'status', 20 )->default( 'active' )->index();
                $table->timestamps();
            } );
        }

        if ( Schema::hasTable( 'orders' ) ) {
            Schema::table( 'orders', function ( Blueprint $table ) {
                if ( ! Schema::hasColumn( 'orders', 'is_pre_order' ) ) {
                    $table->boolean( 'is_pre_order' )->default( false )->index();
                }
                if ( ! Schema::hasColumn( 'orders', 'expected_delivery_date' ) ) {
                    $table->date( 'expected_delivery_date' )->nullable()->index();
                }
                if ( ! Schema::hasColumn( 'orders', 'pre_order_payment_type' ) ) {
                    $table->string( 'pre_order_payment_type', 20 )->nullable(); // advance|full
                }
                if ( ! Schema::hasColumn( 'orders', 'pre_order_reminder_date' ) ) {
                    $table->date( 'pre_order_reminder_date' )->nullable();
                }
            } );
        }

        if ( Schema::hasTable( 'carts' ) && ! Schema::hasColumn( 'carts', 'pre_order_payment_type' ) ) {
            Schema::table( 'carts', function ( Blueprint $table ) {
                $table->string( 'pre_order_payment_type', 20 )->nullable();
            } );
        }
    }

    public function down(): void
    {
        if ( Schema::hasTable( 'carts' ) && Schema::hasColumn( 'carts', 'pre_order_payment_type' ) ) {
            Schema::table( 'carts', function ( Blueprint $table ) {
                $table->dropColumn( 'pre_order_payment_type' );
            } );
        }

        if ( Schema::hasTable( 'orders' ) ) {
            Schema::table( 'orders', function ( Blueprint $table ) {
                foreach ( ['is_pre_order', 'expected_delivery_date', 'pre_order_payment_type', 'pre_order_reminder_date'] as $column ) {
                    if ( Schema::hasColumn( 'orders', $column ) ) {
                        $table->dropColumn( $column );
                    }
                }
            } );
        }

        Schema::dropIfExists( 'product_pre_orders' );
    }
};
