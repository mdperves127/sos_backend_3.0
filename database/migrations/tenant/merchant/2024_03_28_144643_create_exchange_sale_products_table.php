<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\PosSales;
use App\Models\Product;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('exchange_sale_products', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(PosSales::class);
            $table->foreignIdFor(Product::class);
            $table->integer('unit_id')->nullable();
            $table->integer('size_id')->nullable();
            $table->integer('color_id')->nullable();
            $table->integer('sale_qty')->default(0);
            $table->integer('return_qty')->default(0);
            $table->decimal('rate')->default(0);
            $table->decimal('sub_total')->default(0);
            $table->string('remark')->nullable();
            $table->string('type')->nullable();
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
        Schema::dropIfExists('exchange_sale_products');
    }
};
