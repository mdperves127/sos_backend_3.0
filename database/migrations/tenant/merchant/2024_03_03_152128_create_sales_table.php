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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->integer('customer_id');
            $table->integer('vendor_id');
            $table->integer('user_id');
            $table->integer('source_id');
            $table->string('barcode');
            $table->string('sale_date')->nullable();
            $table->integer('payment_id')->nullable();
            $table->decimal('paid_amount')->default(0);
            $table->decimal('total_price')->default(0);
            $table->decimal('due_amount')->default(0);
            $table->decimal('sale_discount')->default(0);
            $table->integer('total_qty')->default(0);
            $table->enum('payment_status',['paid','due'])->nullable();
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
        Schema::dropIfExists('sales');
    }
};
