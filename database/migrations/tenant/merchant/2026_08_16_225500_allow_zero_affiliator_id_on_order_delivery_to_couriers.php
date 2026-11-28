<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow affiliator_id = 0 when the order has no dropshipper.
     */
    public function up()
    {
        if ( ! Schema::hasTable( 'order_delivery_to_couriers' ) ) {
            return;
        }

        $foreignKeys = collect( DB::select( "SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'order_delivery_to_couriers'
              AND COLUMN_NAME = 'affiliator_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL" ) )
            ->pluck( 'CONSTRAINT_NAME' );

        foreach ( $foreignKeys as $foreignKey ) {
            DB::statement( "ALTER TABLE `order_delivery_to_couriers` DROP FOREIGN KEY `{$foreignKey}`" );
        }

        DB::table( 'order_delivery_to_couriers' )
            ->whereNull( 'affiliator_id' )
            ->update( ['affiliator_id' => 0] );

        DB::statement( 'ALTER TABLE `order_delivery_to_couriers` MODIFY `affiliator_id` BIGINT UNSIGNED NOT NULL DEFAULT 0' );
    }

    public function down()
    {
        if ( ! Schema::hasTable( 'order_delivery_to_couriers' ) ) {
            return;
        }

        DB::statement( 'ALTER TABLE `order_delivery_to_couriers` MODIFY `affiliator_id` BIGINT UNSIGNED NULL' );
    }
};
