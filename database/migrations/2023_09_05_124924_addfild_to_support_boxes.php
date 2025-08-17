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
        Schema::table('support_boxes', function (Blueprint $table) {
            $table->text('subject')->nullable();
            $table->integer('is_close')->default(0);
            $table->float('rating')->nullable();

        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('support_boxes', function (Blueprint $table) {
            $table->dropColumn('subject');
            $table->dropColumn('is_close');
            $table->dropColumn('rating');
        });
    }
};
