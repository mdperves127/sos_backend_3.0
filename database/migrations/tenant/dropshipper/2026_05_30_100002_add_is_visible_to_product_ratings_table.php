<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'product_ratings', function ( Blueprint $table ) {
            if ( !Schema::hasColumn( 'product_ratings', 'is_visible' ) ) {
                $table->boolean( 'is_visible' )->default( false )->after( 'comment' );
            }
        } );
    }

    public function down(): void {
        Schema::table( 'product_ratings', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'product_ratings', 'is_visible' ) ) {
                $table->dropColumn( 'is_visible' );
            }
        } );
    }
};
