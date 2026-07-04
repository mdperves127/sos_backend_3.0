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
        Schema::create('cms_settings', function (Blueprint $table) {
            $table->id();
            $table->string('app_name');
            $table->string('home_page_title');
            $table->string('color_primary');

            $table->string('logo');

            $table->enum('theme',['one','two', 'three', 'four'])->nullable();

            $table->string('seo_meta_title');
            $table->string('seo_meta_description');
            $table->string('seo_meta_keywords');
            $table->string('seo_meta_image');

            $table->longText('scripts_google_analytics')->nullable();
            $table->longText('scripts_google_adsense')->nullable();
            $table->longText('scripts_google_recaptcha')->nullable();
            $table->longText('scripts_facebook_pixel')->nullable();
            $table->longText('scripts_facebook_messenger')->nullable();
            $table->longText('scripts_whatsapp_chat')->nullable();
            $table->longText('scripts_google_tag_manager')->nullable();

            $table->string('footer_logo');
            $table->string('footer_description');
            $table->string('footer_contact_number_one');
            $table->string('footer_contact_address_one');
            $table->string('footer_contact_number_two');
            $table->string('footer_contact_address_two');
            $table->string('footer_copyright_text');
            $table->string('footer_payment_methods');
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
        Schema::dropIfExists('cms_settings');
    }
};
