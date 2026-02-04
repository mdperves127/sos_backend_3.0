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
            $table->string('fb_url');
            $table->string('x_url');
            $table->string('instagram_url');
            $table->string('youtube_url');
            $table->string('tiktok_url');
            $table->string('telegram_url');
            $table->string('whatsapp_url');
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
            $table->dropColumn('fb_url');
            $table->dropColumn('x_url');
            $table->dropColumn('instagram_url');
            $table->dropColumn('youtube_url');
            $table->dropColumn('tiktok_url');
            $table->dropColumn('telegram_url');
            $table->dropColumn('whatsapp_url');
        });
    }
};
