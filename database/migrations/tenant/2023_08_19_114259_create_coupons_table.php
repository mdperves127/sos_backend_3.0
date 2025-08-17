<?php

use App\Enums\Coupon;
use App\Enums\Status;
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
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type',[Coupon::Flat->value,Coupon::Percentage->value]);
            $table->float('amount');
            $table->float('commission');
            $table->string('expire_date');
            $table->integer('limitation');
            $table->foreignIdFor(User::class);
            $table->enum('status',[Status::Active->value,Status::Deactivate->value])->default(Status::Active->value);
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
        Schema::dropIfExists('coupons');
    }
};
