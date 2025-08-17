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
        Schema::create('vendor_employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->integer('all_request')->nullable();
            $table->integer('active_request')->nullable();
            $table->integer('pending_request')->nullable();
            $table->integer('reject_request')->nullable();
            $table->integer('expired_request')->nullable();
            $table->integer('create_service')->nullable();
            $table->integer('all_service')->nullable();
            $table->integer('service_order')->nullable();
            $table->integer('coupon')->nullable();
            $table->integer('membership')->nullable();
            $table->integer('advertiser')->nullable();
            $table->integer('service_buy')->nullable();
            $table->integer('recharge')->nullable();
            $table->integer('withdraw')->nullable();
            $table->integer('recharge_history')->nullable();
            $table->integer('create_support')->nullable();
            $table->integer('all_support')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('vendor_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vendor_employees');
    }
};
