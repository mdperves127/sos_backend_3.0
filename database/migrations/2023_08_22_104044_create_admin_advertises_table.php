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
        Schema::create('admin_advertises', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_objective');
            $table->unsignedBigInteger('user_id');
            $table->string('campaign_name');
            $table->string('conversion_location');
            $table->string('performance_goal');
            // $table->string('platforms');
            $table->string('budget');
            $table->string('budget_amount');
            $table->string('start_date');
            $table->string('end_date');
            $table->string('age');
            $table->string('gender');
            $table->string('detail_targeting');
            $table->string('country');
            $table->string('city');
            $table->string('device');
            $table->string('platform');
            $table->string('inventory');
            $table->string('format');

            // $table->longText('primary_text');
            // $table->string('media');
            // $table->string('heading');
            // $table->longText('description');
            // $table->string('call_to_action');
            $table->text('ad_creative');

            $table->string('destination')->nullable();
            $table->string('tracking')->nullable();
            $table->string('url_perimeter')->nullable();
            $table->string('number')->nullable();
            $table->longText('last_description')->nullable();
            $table->string('status');
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
        Schema::dropIfExists('admin_advertises');
    }
};
