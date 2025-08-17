<?php

use App\Models\User;
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
        Schema::create('payment_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class);
            $table->string('trxid')->nullable();
            $table->string('amount')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('transition_type')->nullable()->comment('subscription','product purchase');
            $table->string('balance_type')->nullable();
            $table->string('coupon')->nullable();
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
        Schema::dropIfExists('payment_histories');
    }
};
