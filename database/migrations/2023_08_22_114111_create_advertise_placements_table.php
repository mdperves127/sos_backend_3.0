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
        Schema::create('advertise_placements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('advertise_id');
            $table->text('feeds')->nullable();
            $table->text('story_reels')->nullable();
            $table->text('adds_video_and_reels')->nullable();
            $table->string('search_result')->nullable();
            $table->longText('messages')->nullable();
            $table->longText('apps_and_sites')->nullable();
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
        Schema::dropIfExists('advertise_placements');
    }
};
