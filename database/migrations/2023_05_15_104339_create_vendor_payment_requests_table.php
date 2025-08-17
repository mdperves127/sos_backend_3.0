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
        Schema::create('vendor_payment_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_bank_id');
            $table->string('vendor_bank_number');
            $table->string('balance');
            $table->string('transition_id');
            $table->string('screenshot')->nullable();
            $table->text('reference_field')->nullable();
            $table->unsignedBigInteger('vendor_id');
            $table->string('bank_name')->nullable();
            $table->string('bank_number')->nullable();
            $table->string('status')->default('pending');
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
        Schema::dropIfExists('vendor_payment_requests');
    }
};
