<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align tenant coupon discount_type with app values (flat | percentage).
 * Also repairs rows where invalid "flat" was stored as empty under the old enum.
 */
return new class extends Migration {
    public function up(): void
    {
        if ( ! Schema::hasTable( 'tenant_coupons' ) ) {
            return;
        }

        // Expand enum to accept both legacy "fixed" and app "flat".
        DB::statement( "ALTER TABLE `tenant_coupons` MODIFY `discount_type` ENUM('percentage','fixed','flat') NOT NULL" );

        // Repair empty / invalid values from prior flat inserts.
        DB::table( 'tenant_coupons' )
            ->where( function ( $q ) {
                $q->where( 'discount_type', '' )
                    ->orWhereNull( 'discount_type' );
            } )
            ->update( ['discount_type' => 'flat'] );

        // Normalize legacy "fixed" → "flat" for API consistency.
        DB::table( 'tenant_coupons' )
            ->where( 'discount_type', 'fixed' )
            ->update( ['discount_type' => 'flat'] );

        DB::statement( "ALTER TABLE `tenant_coupons` MODIFY `discount_type` ENUM('percentage','flat') NOT NULL" );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'tenant_coupons' ) ) {
            return;
        }

        DB::statement( "ALTER TABLE `tenant_coupons` MODIFY `discount_type` ENUM('percentage','fixed','flat') NOT NULL" );

        DB::table( 'tenant_coupons' )
            ->where( 'discount_type', 'flat' )
            ->update( ['discount_type' => 'fixed'] );

        DB::statement( "ALTER TABLE `tenant_coupons` MODIFY `discount_type` ENUM('percentage','fixed') NOT NULL" );
    }
};
