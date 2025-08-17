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
        Schema::create('pending_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('affiliator_id')->comment('user-id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->string('qty');
            $table->string('amount')->nullable();
            $table->string('status')->default('pending')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('pending_balances');
    }
};
