<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->text('f_banner_group_title_image')->nullable();
            $table->text('f_banner_image_1')->nullable();
            $table->text('f_banner_image_2')->nullable();
            $table->text('f_banner_image_3')->nullable();
            $table->text('f_feature_image_4')->nullable();
            $table->text('f_feature_image_5')->nullable();
            $table->text('f_feature_image_6')->nullable();
            $table->text('f_feature_image_7')->nullable();
            $table->text('f_feature_image_8')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'f_banner_group_title_image',
                'f_banner_image_1',
                'f_banner_image_2',
                'f_banner_image_3',
                'f_feature_image_4',
                'f_feature_image_5',
                'f_feature_image_6',
                'f_feature_image_7',
                'f_feature_image_8',
            ]);
        });
    }
};
