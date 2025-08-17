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
        Schema::create('vendor_services', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('service_category_id');
            $table->unsignedBigInteger('service_sub_category_id');
            $table->float('rating')->default(0.00);
            $table->string('title');
            $table->text('description');
            $table->text('tags');
            $table->string('contract');
            $table->enum('status',['active','pending','rejected'])->default('pending');
            $table->float('commission');
            $table->enum('commission_type',['flat','percentage']);
            $table->string('image');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vendor_services');
    }
};
