<?php

use App\Models\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Supplier;
use App\Models\ProductPurchase;
use App\Models\User;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->nullable();
            $table->foreignIdFor(Supplier::class)->nullable();
            $table->foreignIdFor(ProductPurchase::class)->nullable();
            $table->foreignIdFor(PaymentMethod::class)->nullable();
            $table->integer('vendor_id')->nullable();
            $table->string('chalan_no')->nullable();
            $table->string('date')->nullable();
            $table->decimal('paid_amount')->default(0);
            $table->decimal('due_amount')->default(0);
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
        Schema::dropIfExists('supplier_payments');
    }
};
