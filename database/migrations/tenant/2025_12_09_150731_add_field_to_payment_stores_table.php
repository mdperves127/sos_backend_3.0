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
        Schema::table('payment_stores', function (Blueprint $table) {
            if ( ! Schema::hasColumn( 'payment_stores', 'last_status' ) ) {
                $table->string('last_status')->nullable();
            }

            if ( ! Schema::hasColumn( 'payment_stores', 'order_media' ) ) {
                $table->string('order_media')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('payment_stores', function (Blueprint $table) {
            if ( Schema::hasColumn( 'payment_stores', 'order_media' ) ) {
                $table->dropColumn('order_media');
            }

            if ( Schema::hasColumn( 'payment_stores', 'last_status' ) ) {
                $table->dropColumn('last_status');
            }
        });
    }
};
