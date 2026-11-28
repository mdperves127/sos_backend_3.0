<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Supplier;
use App\Models\Warehouse;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('distributor_price')->nullable();
            $table->integer('alert_qty')->nullable()->nullable();
            $table->foreignIdFor(Supplier::class);
            $table->foreignIdFor(Warehouse::class);
            $table->string('exp_date')->nullable();
            $table->string('barcode')->nullable();
            $table->string('warranty')->nullable();
            $table->integer('is_feature')->nullable();
            $table->integer('is_affiliate')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('distributor_price')->nullable();
            $table->integer('alert_qty')->nullable()->nullable();
            $table->foreignIdFor(Supplier::class);
            $table->foreignIdFor(Warehouse::class);
            $table->string('exp_date')->nullable();
            $table->string('barcode')->nullable();
            $table->string('warranty')->nullable();
            $table->integer('is_feature')->nullable();
            $table->integer('is_affiliate')->nullable();
        });
    }
};
