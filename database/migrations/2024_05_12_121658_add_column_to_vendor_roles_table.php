<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        Schema::table( 'vendor_roles', function ( Blueprint $table ) {
            $table->integer( 'stock_shortage_report' )->nullable();
            $table->integer( 'top_repeat_customer' )->nullable();
            $table->integer( 'sales_report_daily' )->nullable();
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::table( 'vendor_roles', function ( Blueprint $table ) {
            $table->dropColumn( 'stock_shortage_report' );
            $table->dropColumn( 'top_repeat_customer' );
            $table->dropColumn( 'sales_report_daily' );
        } );
    }
};
