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
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->integer('return_qty')->nullable()->default(0);
            $table->decimal('return_amount')->nullable()->default(0);
            $table->string('return_date')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('pos_sales', function (Blueprint $table) {
            //
        });
    }
};
