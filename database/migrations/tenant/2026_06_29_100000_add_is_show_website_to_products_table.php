<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'products', function ( Blueprint $table ) {
            if ( !Schema::hasColumn( 'products', 'is_show_website' ) ) {
                $table->unsignedTinyInteger( 'is_show_website' )->default( 1 )->after( 'status' );
            }
        } );

        if ( Schema::hasColumn( 'products', 'is_show_website' ) ) {
            DB::table( 'products' )->whereNull( 'is_show_website' )->update( ['is_show_website' => 1] );
        }
    }

    public function down(): void {
        Schema::table( 'products', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'products', 'is_show_website' ) ) {
                $table->dropColumn( 'is_show_website' );
            }
        } );
    }
};
