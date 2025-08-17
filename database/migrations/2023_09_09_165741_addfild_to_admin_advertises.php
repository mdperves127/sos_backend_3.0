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
        Schema::table('admin_advertises', function (Blueprint $table) {
            $table->integer('is_paid')->default(0)->nullable()->comment('0 = unpain, 1 = paind');
            $table->string('trxid')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('admin_advertises', function (Blueprint $table) {
            $table->dropColumn(['is_paid','trxid']);
        });
    }
};
