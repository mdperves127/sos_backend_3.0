<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Product;
use App\Models\PosSales;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('pos_sales_details', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(PosSales::class);
            $table->foreignIdFor(Product::class);
            $table->integer('unit_id')->nullable();
            $table->integer('size_id')->nullable();
            $table->integer('color_id')->nullable();
            $table->integer('qty')->default(0);
            $table->decimal('rate')->default(0);
            $table->decimal('sub_total')->default(0);
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
        Schema::dropIfExists('pos_sales_details');
    }
};
