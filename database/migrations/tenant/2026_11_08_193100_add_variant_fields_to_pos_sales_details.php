<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ( ! Schema::hasTable( 'pos_sales_details' ) ) {
            return;
        }

        Schema::table( 'pos_sales_details', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'pos_sales_details', 'variant_id' ) ) {
                $table->unsignedBigInteger( 'variant_id' )->nullable()->after( 'product_id' );
            }
            if ( ! Schema::hasColumn( 'pos_sales_details', 'variant_label' ) ) {
                $table->string( 'variant_label', 255 )->nullable()->after( 'variant_id' );
            }
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'pos_sales_details' ) ) {
            return;
        }

        Schema::table( 'pos_sales_details', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'pos_sales_details', 'variant_label' ) ) {
                $table->dropColumn( 'variant_label' );
            }
            if ( Schema::hasColumn( 'pos_sales_details', 'variant_id' ) ) {
                $table->dropColumn( 'variant_id' );
            }
        } );
    }
};
