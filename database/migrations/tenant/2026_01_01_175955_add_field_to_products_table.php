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
            $table->integer('market_place_brand_id')->nullable();
            $table->integer('market_place_category_id')->nullable();
            $table->integer('market_place_subcategory_id')->nullable();
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
            $table->dropColumn('market_place_brand_id');
            $table->dropColumn('market_place_category_id');
            $table->dropColumn('market_place_subcategory_id');
        });
    }
};
