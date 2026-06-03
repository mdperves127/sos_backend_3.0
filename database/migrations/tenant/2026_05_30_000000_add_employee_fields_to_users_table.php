<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'users', function ( Blueprint $table ) {
            if ( !Schema::hasColumn( 'users', 'number' ) ) {
                $table->string( 'number' )->nullable()->after( 'email' );
            }
            if ( !Schema::hasColumn( 'users', 'status' ) ) {
                $table->string( 'status' )->default( 'active' )->nullable()->after( 'password' );
            }
            if ( !Schema::hasColumn( 'users', 'email_verified_at' ) ) {
                $table->timestamp( 'email_verified_at' )->nullable();
            }
            if ( !Schema::hasColumn( 'users', 'vendor_role_id' ) ) {
                $table->unsignedBigInteger( 'vendor_role_id' )->nullable()->after( 'role_type' );
            }
        } );

        // role_type: admin (owner), employee (staff with vendor_role permissions), tenant_user (customers)
        if ( Schema::hasColumn( 'users', 'role_type' ) ) {
            DB::statement( "ALTER TABLE users MODIFY role_type VARCHAR(32) NULL" );
        } else {
            Schema::table( 'users', function ( Blueprint $table ) {
                $table->string( 'role_type', 32 )->nullable()->after( 'password' );
            } );
        }

        foreach ( ['is_employee', 'vendor_id', 'role_as'] as $column ) {
            if ( Schema::hasColumn( 'users', $column ) ) {
                Schema::table( 'users', function ( Blueprint $table ) use ( $column ) {
                    $table->dropColumn( $column );
                } );
            }
        }
    }

    public function down(): void {
        Schema::table( 'users', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'users', 'vendor_role_id' ) ) {
                $table->dropColumn( 'vendor_role_id' );
            }
            foreach ( ['number', 'status', 'email_verified_at'] as $column ) {
                if ( Schema::hasColumn( 'users', $column ) ) {
                    $table->dropColumn( $column );
                }
            }
        } );
    }
};
