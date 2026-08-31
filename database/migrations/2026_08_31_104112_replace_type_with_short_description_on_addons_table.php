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

        Schema::table( 'addons', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'addons', 'type' ) ) {
                $table->dropColumn( 'type' );
            }

            if ( ! Schema::hasColumn( 'addons', 'short_description' ) ) {
                $table->string( 'short_description' )->nullable()->after( 'for_tenant' );
            }
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'addons' ) ) {
            return;
        }

        Schema::table( 'addons', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'addons', 'short_description' ) ) {
                $table->dropColumn( 'short_description' );
            }

            if ( ! Schema::hasColumn( 'addons', 'type' ) ) {
                $table->enum( 'type', ['number', 'string', 'yes', 'no'] )->after( 'for_tenant' );
            }
        } );
    }
};
