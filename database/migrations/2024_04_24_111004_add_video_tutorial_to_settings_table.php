<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        Schema::table( 'settings', function ( Blueprint $table ) {
            $table->text( 'pos_video_tutorial' )->nullable();
            $table->string( 'coupon_title' )->nullable();
            $table->text( 'coupon_description' )->nullable();
            $table->text( 'coupon_video_tutorial' )->nullable();
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::table( 'settings', function ( Blueprint $table ) {
            $table->dropColumn( 'pos_video_tutorial' );
            $table->dropColumn( 'coupon_title' );
            $table->dropColumn( 'coupon_description' );
            $table->dropColumn( 'coupon_video_tutorial' );
        } );
    }
};
