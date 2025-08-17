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
            $table->integer('exchange_qty')->nullable()->default(0);
            $table->decimal('exchange_amount')->nullable()->default(0);
            $table->string('exchange_date')->nullable();
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
            $table->dropColumn('exchange_qty');
            $table->dropColumn('exchange_amount');
            $table->dropColumn('exchange_date');
        });
    }
};
