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
        Schema::table('products', function (Blueprint $table) {
            $table->enum('product_type', ['system', 'woocommerce'])->default('system')->after('discount_percentage');
            $table->string('wc_product_id')->nullable()->after('product_type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $dropColumns = ['product_type', 'wc_product_id'];
            $table->dropColumn($dropColumns);
        });
    }
};
