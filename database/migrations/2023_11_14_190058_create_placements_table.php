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
        Schema::create('placements', function (Blueprint $table) {
            $table->id();
            $table->string('colum_name');
            $table->text('audience_age')->nullable();
            $table->text('device')->nullable();
            $table->text('platform')->nullable();
            $table->text('feed')->nullable();
            $table->text('store_reel')->nullable();
            $table->text('video_reel')->nullable();
            $table->text('search_reel')->nullable();
            $table->text('messages_reel')->nullable();
            $table->text('apps_web')->nullable();
            $table->text('add_format')->nullable();
            $table->text('call_to_action')->nullable();
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
        Schema::dropIfExists('placements');
    }
};
