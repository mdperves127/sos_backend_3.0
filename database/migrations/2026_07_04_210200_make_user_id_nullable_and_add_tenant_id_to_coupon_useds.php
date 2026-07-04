<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if ( ! Schema::hasTable( 'coupon_useds' ) ) {
            return;
        }

        $foreignKeys = collect( DB::select( "SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'coupon_useds'
              AND COLUMN_NAME = 'user_id'
              AND REFERENCED_TABLE_NAME IS NOT NULL" ) )
            ->pluck( 'CONSTRAINT_NAME' );

        foreach ( $foreignKeys as $foreignKey ) {
            DB::statement( "ALTER TABLE `coupon_useds` DROP FOREIGN KEY `{$foreignKey}`" );
        }

        DB::statement( 'ALTER TABLE `coupon_useds` MODIFY `user_id` BIGINT UNSIGNED NULL' );

        if ( ! Schema::hasColumn( 'coupon_useds', 'tenant_id' ) ) {
            Schema::table( 'coupon_useds', function ( Blueprint $table ) {
                $table->string( 'tenant_id' )->nullable()->after( 'user_id' );
            } );
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if ( ! Schema::hasTable( 'coupon_useds' ) ) {
            return;
        }

        if ( Schema::hasColumn( 'coupon_useds', 'tenant_id' ) ) {
            Schema::table( 'coupon_useds', function ( Blueprint $table ) {
                $table->dropColumn( 'tenant_id' );
            } );
        }
    }
};
