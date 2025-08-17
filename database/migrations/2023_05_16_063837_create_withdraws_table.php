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
        Schema::create('withdraws', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('amount');
            $table->string('bank_name')->nullable();
            $table->string('ac_or_number')->nullable();
            $table->string('holder_name')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('admin_transition_id')->nullable();
            $table->string('admin_screenshot')->nullable();
            $table->string('admin_bank_name')->nullable();
            $table->string('role')->nullable();
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
        Schema::dropIfExists('withdraws');
    }
};
