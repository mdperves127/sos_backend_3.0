<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profile picture path for tenant users (admin / employee / customer).
 */
return new class extends Migration {
    public function up(): void
    {
        if ( ! Schema::hasTable( 'users' ) ) {
            return;
        }

        Schema::table( 'users', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'users', 'image' ) ) {
                $table->string( 'image' )->nullable()->after( 'name' );
            }
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'users' ) ) {
            return;
        }

        Schema::table( 'users', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'users', 'image' ) ) {
                $table->dropColumn( 'image' );
            }
        } );
    }
};
