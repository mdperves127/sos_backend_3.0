<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Product;
use App\Models\User;
use App\Models\Supplier;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('product_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Product::class)->nullable();
            $table->foreignIdFor(User::class);
            $table->foreignIdFor(Supplier::class);
            $table->string('chalan_no')->nullable();
            $table->string('purchase_date')->nullable();
            $table->integer('payment_id')->nullable();
            $table->decimal('paid_amount')->default(0);
            $table->decimal('total_price')->default(0);
            $table->decimal('due_amount')->default(0);
            $table->decimal('purchase_discount')->default(0);
            $table->integer('total_qty')->default(0);
            $table->text('note')->nullable();
            $table->enum('status',['ordered','received'])->default('ordered');
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
        Schema::dropIfExists('product_purchases');
    }
};
