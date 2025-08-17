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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->integer('service_qty')->nullable();
            $table->integer('product_qty')->nullable();
            $table->integer('affiliate_request')->nullable();

            $table->integer('product_request')->nullable();
            $table->integer('product_approve')->nullable();
            $table->integer('service_create')->nullable();
        });
    }


    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('service_qty');
            $table->dropColumn('product_qty');
            $table->dropColumn('affiliate_request');


            $table->dropColumn('product_request');
            $table->dropColumn('product_approve');
            $table->dropColumn('service_create');
        });
    }
};
