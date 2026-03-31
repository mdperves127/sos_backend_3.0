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
            $table->string('f_banner_group_title_image')->nullable();
            $table->string('f_banner_image_1')->nullable();
            $table->string('f_banner_image_2')->nullable();
            $table->string('f_banner_image_3')->nullable();
            $table->string('f_feature_image_4')->nullable();
            $table->string('f_feature_image_5')->nullable();
            $table->string('f_feature_image_6')->nullable();
            $table->string('f_feature_image_8')->nullable();
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
            $table->dropColumn('f_banner_group_title_image');
            $table->dropColumn('f_banner_image_1');
            $table->dropColumn('f_banner_image_2');
            $table->dropColumn('f_banner_image_3');
            $table->dropColumn('f_feature_image_4');
            $table->dropColumn('f_feature_image_5');
            $table->dropColumn('f_feature_image_6');
            $table->dropColumn('f_feature_image_8');
        });
    }
};
