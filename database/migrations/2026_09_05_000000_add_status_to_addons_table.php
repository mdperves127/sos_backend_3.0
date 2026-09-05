<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ( ! Schema::hasTable( 'addons' ) ) {
            return;
        }

        if ( ! Schema::hasColumn( 'addons', 'status' ) ) {
            Schema::table( 'addons', function ( Blueprint $table ) {
                $table->enum( 'status', ['active', 'inactive'] )
                    ->default( 'active' )
                    ->after( 'for_tenant' );
            } );
        }
    }

    public function down(): void
    {
        if ( Schema::hasTable( 'addons' ) && Schema::hasColumn( 'addons', 'status' ) ) {
            Schema::table( 'addons', function ( Blueprint $table ) {
                $table->dropColumn( 'status' );
            } );
        }
    }
};
