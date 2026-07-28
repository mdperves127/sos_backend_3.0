<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ( Schema::hasColumn( 'organization_twos', 'photo' ) && ! Schema::hasColumn( 'organization_twos', 'image' ) ) {
            Schema::table( 'organization_twos', function ( Blueprint $table ) {
                $table->renameColumn( 'photo', 'image' );
            } );
        }
    }

    public function down(): void
    {
        if ( Schema::hasColumn( 'organization_twos', 'image' ) && ! Schema::hasColumn( 'organization_twos', 'photo' ) ) {
            Schema::table( 'organization_twos', function ( Blueprint $table ) {
                $table->renameColumn( 'image', 'photo' );
            } );
        }
    }
};
