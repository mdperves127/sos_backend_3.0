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
        Schema::create('advertise_audience_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('advertise_id');
            $table->text('file');
            $table->foreign('advertise_id')->references('id')->on('admin_advertises')->onDelete('cascade');
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
        Schema::dropIfExists('advertise_audience_files');
    }
};
