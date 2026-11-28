<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Customer;
use App\Models\User;
use App\Models\PaymentMethod;
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
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->nullable();
            $table->foreignIdFor(Customer::class)->nullable();
            $table->foreignIdFor(PosSales::class)->nullable();
            $table->foreignIdFor(PaymentMethod::class)->nullable();
            $table->integer('vendor_id')->nullable();
            $table->string('invoice_no')->nullable();
            $table->string('date')->nullable();
            $table->decimal('paid_amount')->default(0);
            $table->decimal('due_amount')->default(0);
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
        Schema::dropIfExists('customer_payments');
    }
};
