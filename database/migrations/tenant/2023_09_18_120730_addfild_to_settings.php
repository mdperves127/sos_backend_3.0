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
        Schema::table('settings', function (Blueprint $table) {
            $table->string('count_two')->nullable();
            $table->string('member_title')->nullable();
            $table->string('member_heading')->nullable();
            $table->longText('home_banner_description')->change()->nullable();
            $table->longText('chose_card_one_description')->change()->nullable();
            $table->longText('chose_card_two_description')->change()->nullable();
            $table->longText('chose_card_three_description')->change()->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['count_two','member_title','member_heading']);
        });
    }
};
