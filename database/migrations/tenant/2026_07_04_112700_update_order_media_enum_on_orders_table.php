<?php

use Illuminate\Database\Migrations\Migration;
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
        if ( ! Schema::hasColumn( 'orders', 'order_media' ) ) {
            return;
        }

        DB::statement( "ALTER TABLE `orders` MODIFY `order_media` ENUM('Affiliator','Direct','Woocommerce','website','website-guest','dropshipper') NULL" );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if ( ! Schema::hasColumn( 'orders', 'order_media' ) ) {
            return;
        }

        DB::statement( "ALTER TABLE `orders` MODIFY `order_media` ENUM('Affiliator','Direct') NULL" );
    }
};
