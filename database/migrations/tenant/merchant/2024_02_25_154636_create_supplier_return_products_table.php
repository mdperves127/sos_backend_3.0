<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Product;
use App\Models\ProductPurchase;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('supplier_return_products', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ProductPurchase::class);
            $table->foreignIdFor(Product::class);
            $table->integer('r_unit_id')->nullable();
            $table->integer('r_size_id')->nullable();
            $table->integer('r_color_id')->nullable();
            $table->integer('r_purchase_qty')->default(0);
            $table->integer('return_qty')->default(0);
            $table->decimal('r_rate')->default(0);
            $table->decimal('r_sub_total')->default(0);
            $table->string('remark')->nullable();
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
        Schema::dropIfExists('supplier_return_products');
    }
};
