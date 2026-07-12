<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'products', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'products', 'dropshipper_message' ) ) {
                $table->longText( 'dropshipper_message' )->nullable();
            }
        } );
    }

    public function down(): void {
        Schema::table( 'products', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'products', 'dropshipper_message' ) ) {
                $table->dropColumn( 'dropshipper_message' );
            }
        } );
    }
};
