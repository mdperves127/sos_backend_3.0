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
            $table->string('last_status')->nullable();
            $table->string('order_media')->nullable();
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
            $table->dropColumn('last_status');
            $table->dropColumn('order_media');
        });
    }
};
