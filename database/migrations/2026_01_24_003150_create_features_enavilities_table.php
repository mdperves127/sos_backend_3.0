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
        Schema::create('features_enavilities', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enventory')->default(true);
            $table->boolean('is_pos')->default(true);
            $table->boolean('is_dropshipping')->default(true);
            $table->boolean('is_ecommerce')->default(true);
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
        Schema::dropIfExists('features_enavilities');
    }
};
