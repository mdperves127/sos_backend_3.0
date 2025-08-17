<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        Schema::create( 'products', function ( Blueprint $table ) {
            $table->id();
            $table->integer( 'category_id' );
            $table->integer( 'subcategory_id' )->nullable();
            $table->integer( 'brand_id' )->nullable();
            $table->integer( 'user_id' );
            $table->string( 'slug' );
            $table->string( 'name' );
            $table->text( 'short_description' )->nullable();
            $table->longText( 'long_description' )->nullable();
            $table->decimal( 'selling_price', 15, 2 )->default( 0 )->nullable();
            $table->decimal( 'original_price', 15, 2 )->default( 0 )->nullable();
            $table->string( 'qty' );
            $table->string( 'image' )->nullable();
            $table->string( 'status' )->nullable();
            $table->string( 'meta_title' )->nullable();
            $table->string( 'meta_keyword' )->nullable();
            $table->string( 'meta_description' )->nullable();
            $table->string( 'tags' )->nullable();
            //  $table->longText('specification')->nullable();
            //  $table->longText('specification_ans')->nullable();
            $table->string( 'commision_type' )->nullable();
            $table->string( 'request' )->nullable();
            $table->string( 'user_type' )->nullable();
            $table->string( 'discount_type' )->nullable();
            $table->string( 'discount_rate' )->nullable();
            $table->text( 'rejected_details' )->nullable();
            $table->timestamps();
            $table->softDeletes();
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::dropIfExists( 'products' );
    }
};
