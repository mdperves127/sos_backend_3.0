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
            $table->integer('wastage_qty')->nullable()->default(0);
            $table->decimal('wastage_amount')->nullable()->default(0);
            $table->string('wastage_date')->nullable();
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
            $table->dropColumn('wastage_qty');
            $table->dropColumn('wastage_amount');
            $table->dropColumn('wastage_date');
        });
    }
};
