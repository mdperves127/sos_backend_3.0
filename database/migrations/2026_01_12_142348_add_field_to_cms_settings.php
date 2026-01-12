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
            $table->string('populer_section_title');
            $table->string('populer_section_banner');
            $table->string('populer_section_category_id_1');
            $table->string('populer_section_subcategory_id_1');
            $table->string('populer_section_category_id_2');
            $table->string('populer_section_subcategory_id_2');
            $table->string('populer_section_category_id_3');
            $table->string('populer_section_subcategory_id_3');
            $table->string('populer_section_category_id_4');
            $table->string('populer_section_subcategory_id_4');
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
            $table->dropColumn('populer_section_title');
            $table->dropColumn('populer_section_banner');
            $table->dropColumn('populer_section_category_id_1');
            $table->dropColumn('populer_section_subcategory_id_1');
            $table->dropColumn('populer_section_category_id_2');
            $table->dropColumn('populer_section_subcategory_id_2');
            $table->dropColumn('populer_section_category_id_3');
            $table->dropColumn('populer_section_subcategory_id_3');
            $table->dropColumn('populer_section_category_id_4');
            $table->dropColumn('populer_section_subcategory_id_4');
        });
    }
};
