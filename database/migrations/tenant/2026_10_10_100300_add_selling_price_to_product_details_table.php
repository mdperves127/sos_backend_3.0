<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'product_details_tenant_product_index';

    public function up()
    {
        Schema::table( 'product_details', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'product_details', 'selling_price' ) ) {
                $table->decimal( 'selling_price', 12, 2 )->nullable();
            }
        } );

        if ( Schema::hasColumn( 'product_details', 'profit_amount' ) ) {
            // Widen integer -> decimal so margins with cents are not truncated.
            Schema::table( 'product_details', function ( Blueprint $table ) {
                $table->decimal( 'profit_amount', 12, 2 )->default( 0 )->change();
            } );
        }

        if ( Schema::hasColumn( 'product_details', 'tenant_id' ) && ! $this->hasIndex( self::INDEX ) ) {
            Schema::table( 'product_details', function ( Blueprint $table ) {
                $table->index( ['tenant_id', 'product_id'], self::INDEX );
            } );
        }
    }

    public function down()
    {
        if ( $this->hasIndex( self::INDEX ) ) {
            Schema::table( 'product_details', function ( Blueprint $table ) {
                $table->dropIndex( self::INDEX );
            } );
        }

        Schema::table( 'product_details', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'product_details', 'selling_price' ) ) {
                $table->dropColumn( 'selling_price' );
            }
        } );
    }

    private function hasIndex( string $name ): bool {
        $indexes = Schema::getConnection()->getDoctrineSchemaManager()->listTableIndexes( 'product_details' );

        return array_key_exists( strtolower( $name ), array_change_key_case( $indexes, CASE_LOWER ) );
    }
};
