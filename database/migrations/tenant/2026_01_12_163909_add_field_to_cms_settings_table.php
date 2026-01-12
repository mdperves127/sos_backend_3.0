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
            $table->string('banner_1');
            $table->string('banner_1_url');
            $table->string('banner_2');
            $table->string('banner_2_url');
            $table->string('banner_3');
            $table->string('banner_3_url');
            
            $table->string('three_column_banner_1');
            $table->string('three_column_banner_1_url');
            $table->string('three_column_banner_2');
            $table->string('three_column_banner_2_url');
            $table->string('three_column_banner_3');
            $table->string('three_column_banner_3_url');
            
            $table->string('two_column_banner_1');
            $table->string('two_column_banner_1_url');
            $table->string('two_column_banner_2');
            $table->string('two_column_banner_2_url');

            $table->string('recomended_category_id_1');
            $table->string('recomended_sub_category_id_1');
            $table->string('recomended_category_id_2');
            $table->string('recomended_sub_category_id_2');
            $table->string('recomended_category_id_3');
            $table->string('recomended_sub_category_id_3');
            $table->string('recomended_category_id_4');
            $table->string('recomended_sub_category_id_4');

            $table->string('best_setting_title');
            $table->string('best_setting_category_id_1');
            $table->string('best_setting_sub_category_id_1');
            $table->string('best_setting_category_id_2');
            $table->string('best_setting_sub_category_id_2');
            $table->string('best_setting_category_id_3');
            $table->string('best_setting_sub_category_id_3');
            $table->string('best_setting_category_id_4');
            $table->string('best_setting_sub_category_id_4');

            $table->string('best_category_id');
            $table->string('best_sub_category_id');
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
            $table->dropColumn('banner_1');
            $table->dropColumn('banner_1_url');
            $table->dropColumn('banner_2');
            $table->dropColumn('banner_2_url');
            $table->dropColumn('banner_3');
            $table->dropColumn('banner_3_url');
            
            $table->dropColumn('three_column_banner_1');
            $table->dropColumn('three_column_banner_1_url');
            $table->dropColumn('three_column_banner_2');
            $table->dropColumn('three_column_banner_2_url');
            $table->dropColumn('three_column_banner_3');
            $table->dropColumn('three_column_banner_3_url');
            
            $table->dropColumn('two_column_banner_1');
            $table->dropColumn('two_column_banner_1_url');
            $table->dropColumn('two_column_banner_2');
            $table->dropColumn('two_column_banner_2_url');

            $table->dropColumn('recomended_category_id_1');
            $table->dropColumn('recomended_sub_category_id_1');
            $table->dropColumn('recomended_category_id_2');
            $table->dropColumn('recomended_sub_category_id_2');
            $table->dropColumn('recomended_category_id_3');
            $table->dropColumn('recomended_sub_category_id_3');
            $table->dropColumn('recomended_category_id_4');
            $table->dropColumn('recomended_sub_category_id_4');

            $table->dropColumn('best_setting_title');
            $table->dropColumn('best_setting_category_id_1');
            $table->dropColumn('best_setting_sub_category_id_1');
            $table->dropColumn('best_setting_category_id_2');
            $table->dropColumn('best_setting_sub_category_id_2');
            $table->dropColumn('best_setting_category_id_3');
            $table->dropColumn('best_setting_sub_category_id_3');
            $table->dropColumn('best_setting_category_id_4');
            $table->dropColumn('best_setting_sub_category_id_4');

            $table->dropColumn('best_category_id');
            $table->dropColumn('best_sub_category_id');
        });
    }
};
