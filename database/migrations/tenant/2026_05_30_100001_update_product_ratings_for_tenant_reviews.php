<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'product_ratings', function ( Blueprint $table ) {
            $table->unique( ['user_id', 'product_id'], 'product_ratings_user_product_unique' );
        } );
    }

    public function down(): void {
        Schema::table( 'product_ratings', function ( Blueprint $table ) {
            $table->dropUnique( 'product_ratings_user_product_unique' );
        } );
    }
};
