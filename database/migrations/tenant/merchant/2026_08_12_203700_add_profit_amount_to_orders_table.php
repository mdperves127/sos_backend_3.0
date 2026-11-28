<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table( 'orders', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'orders', 'profit_amount' ) ) {
                $table->decimal( 'profit_amount', 20, 2 )->default( 0 )->nullable()->after( 'afi_amount' );
            }
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table( 'orders', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'orders', 'profit_amount' ) ) {
                $table->dropColumn( 'profit_amount' );
            }
        } );
    }
};
