<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'users', function ( Blueprint $table ) {
            if ( !Schema::hasColumn( 'users', 'role_type' ) ) {
                $table->string( 'role_type', 32 )->nullable();
            }
            if ( !Schema::hasColumn( 'users', 'number' ) ) {
                $table->string( 'number' )->nullable();
            }
            if ( !Schema::hasColumn( 'users', 'status' ) ) {
                $table->string( 'status' )->default( 'active' )->nullable();
            }
            if ( !Schema::hasColumn( 'users', 'email_verified_at' ) ) {
                $table->timestamp( 'email_verified_at' )->nullable();
            }
            if ( !Schema::hasColumn( 'users', 'vendor_role_id' ) ) {
                $table->unsignedBigInteger( 'vendor_role_id' )->nullable();
            }
        } );

        if ( Schema::hasColumn( 'users', 'role_type' ) ) {
            try {
                DB::statement( 'ALTER TABLE users MODIFY role_type VARCHAR(32) NULL' );
            } catch ( \Throwable $e ) {
                // Column may already be VARCHAR on some tenants.
            }
        }
    }

    public function down(): void {
        Schema::table( 'users', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'users', 'vendor_role_id' ) ) {
                $table->dropColumn( 'vendor_role_id' );
            }
        } );
    }
};
