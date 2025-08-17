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
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->text('logo')->nullable();
            $table->string('home_banner_heading')->nullable();
            $table->string('home_banner_description')->nullable();
            $table->string('service_one_title')->nullable();
            $table->string('service_one_heading')->nullable();
            $table->string('org_one_title')->nullable();
            $table->string('org_one_heading')->nullable();
            $table->text('org_one_photo')->nullable();
            $table->text('org_one_video_link')->nullable();
            $table->string('count_one')->nullable();
            $table->string('one_title')->nullable();
            $table->string('count_two_title')->nullable();
            $table->string('count_three')->nullable();
            $table->string('count_three_title')->nullable();
            $table->string('count_four')->nullable();
            $table->string('count_four_title')->nullable();
            $table->string('service_two_title')->nullable();
            $table->string('service_two_heading')->nullable();
            $table->string('org_title')->nullable();
            $table->string('org_heading')->nullable();
            $table->text('org_photo')->nullable();
            $table->string('chose_us_title')->nullable();
            $table->string('chose_us_heading')->nullable();
            $table->string('chose_description')->nullable();
            $table->string('progress_title')->nullable();
            $table->string('progress_value')->nullable();
            $table->string('progres_two_title')->nullable();
            $table->string('progres_two_value')->nullable();
            $table->string('progres_three_title')->nullable();
            $table->string('progres_three_value')->nullable();
            $table->string('progres_four_title')->nullable();
            $table->string('progres_four_value')->nullable();
            $table->string('chose_us_two_title')->nullable();
            $table->string('chose_us_two_heading')->nullable();
            $table->string('testimonial_title')->nullable();
            $table->string('testimonial_heading')->nullable();
            $table->string('chose_card_one_icon')->nullable();
            $table->string('chose_card_one_title')->nullable();
            $table->string('chose_card_one_description')->nullable();
            $table->string('chose_card_two_icon')->nullable();
            $table->string('chose_card_two_title')->nullable();
            $table->string('chose_card_two_description')->nullable();
            $table->string('chose_card_three_icon')->nullable();
            $table->string('chose_card_three_title')->nullable();
            $table->longText('chose_card_three_description')->nullable();
            $table->string('chose_card_four_icon')->nullable();
            $table->string('chose_card_four_title')->nullable();
            $table->longText('chose_card_four_description')->nullable();
            $table->string('partner_title')->nullable();
            $table->string('partner_heading')->nullable();
            $table->string('newsletter_title')->nullable();
            $table->longText('newsletter_description')->nullable();
            $table->string('footer_description')->nullable();
            $table->string('footer_contact_address')->nullable();
            $table->string('footer_contact_number')->nullable();
            $table->string('footer_image')->nullable();
            $table->string('copywright_text')->nullable();
            $table->string('credit_name')->nullable();
            $table->string('credit_link')->nullable();
            $table->string('service_banner_heading')->nullable();
            $table->longText('service_banner_description')->nullable();
            $table->string('advertise_banner_heading')->nullable();
            $table->longText('advertise_banner_description')->nullable();
            $table->text('advertise_banner_image')->nullable();
            $table->string('overview_title')->nullable();
            $table->longText('overview_description')->nullable();
            $table->string('get_sarted_title')->nullable();
            $table->longText('get_sarted_description')->nullable();
            $table->string('about_banner_title')->nullable();
            $table->string('about_banner_heading')->nullable();
            $table->string('about_banner_description')->nullable();
            $table->text('about_banner_image')->nullable();
            $table->string('about_banner_increment_one_count')->nullable();
            $table->string('about_banner_increment_one_title')->nullable();
            $table->string('about_banner_increment_two_count')->nullable();
            $table->string('about_banner_increment_otwo_title')->nullable();
            $table->string('about_banner_increment_othree_count')->nullable();
            $table->string('about_banner_increment_three_title')->nullable();
            $table->string('vision_title')->nullable();
            $table->string('vision_heading')->nullable();
            $table->longText('vision_description')->nullable();
            $table->text('vision_image_one')->nullable();
            $table->text('vision_image_two')->nullable();
            $table->text('vision_image_three')->nullable();
            $table->string('mission_title')->nullable();
            $table->string('mission_heading')->nullable();
            $table->longText('mission_description')->nullable();
            $table->longText('subscription_heading')->nullable();
            $table->longText('subscription_title')->nullable();
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
        Schema::dropIfExists('settings');
    }
};
