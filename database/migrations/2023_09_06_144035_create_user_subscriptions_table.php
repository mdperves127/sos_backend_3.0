<?php

use App\Models\Subscription;
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
        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class);
            $table->foreignIdFor(Subscription::class);
            $table->string('expire_date');

            $table->integer('service_qty')->nullable();
            $table->integer('product_qty')->nullable();
            $table->integer('affiliate_request')->nullable();

            $table->integer('product_request')->nullable();
            $table->integer('product_approve')->nullable();
            $table->integer('service_create')->nullable();

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
        Schema::dropIfExists('user_subscriptions');
    }
};
