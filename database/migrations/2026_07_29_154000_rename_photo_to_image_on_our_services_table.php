<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ( Schema::hasColumn( 'our_services', 'photo' ) && ! Schema::hasColumn( 'our_services', 'image' ) ) {
            Schema::table( 'our_services', function ( Blueprint $table ) {
                $table->renameColumn( 'photo', 'image' );
            } );
        }
    }

    public function down(): void
    {
        if ( Schema::hasColumn( 'our_services', 'image' ) && ! Schema::hasColumn( 'our_services', 'photo' ) ) {
            Schema::table( 'our_services', function ( Blueprint $table ) {
                $table->renameColumn( 'image', 'photo' );
            } );
        }
    }
};
