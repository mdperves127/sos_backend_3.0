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
        Schema::create('vendor_infos', function (Blueprint $table) {
            $table->id();
            $table->integer('vendor_id');
            $table->integer('user_id');
            $table->string('shop_name');
            $table->string('logo');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('address');
            $table->softDeletes();
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
        Schema::dropIfExists('vendor_infos');
    }
};
