<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            if ( !Schema::hasColumn( 'users', 'is_employee' ) ) {
                $table->enum( 'is_employee', ['yes'] )->nullable()->after( 'status' );
            }
            if ( !Schema::hasColumn( 'users', 'vendor_id' ) ) {
                $table->unsignedBigInteger( 'vendor_id' )->nullable()->after( 'is_employee' );
            }
            if ( !Schema::hasColumn( 'users', 'role_as' ) ) {
                $table->string( 'role_as' )->nullable()->after( 'vendor_id' );
            }
            if ( !Schema::hasColumn( 'users', 'email_verified_at' ) ) {
                $table->timestamp( 'email_verified_at' )->nullable();
            }
        } );
    }

    public function down(): void {
        Schema::table( 'users', function ( Blueprint $table ) {
            $columns = ['number', 'status', 'is_employee', 'vendor_id', 'role_as', 'email_verified_at'];
            foreach ( $columns as $column ) {
                if ( Schema::hasColumn( 'users', $column ) ) {
                    $table->dropColumn( $column );
                }
            }
        } );
    }
};
