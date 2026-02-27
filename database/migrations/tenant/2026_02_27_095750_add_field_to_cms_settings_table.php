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
        Schema::table('cms_settings', function (Blueprint $table) {
            $table->string('banner_title')->nullable();
            $table->string('banner_description')->nullable();
            $table->string('extra_section_tittle_4')->nullable();
            $table->string('extra_section_tittle_5')->nullable();
            $table->string('extra_section_tittle_6')->nullable();
            $table->string('fav_icon')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('auth_page_image')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('cms_settings', function (Blueprint $table) {
            $table->dropColumn('banner_title');
            $table->dropColumn('banner_description');
            $table->dropColumn('extra_section_tittle_4');
            $table->dropColumn('extra_section_tittle_5');
            $table->dropColumn('extra_section_tittle_6');
            $table->dropColumn('fav_icon');
            $table->dropColumn('contact_email');
            $table->dropColumn('auth_page_image');
        });
    }
};
