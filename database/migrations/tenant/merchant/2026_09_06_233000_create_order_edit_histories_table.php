<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if ( ! Schema::hasTable( 'order_edit_histories' ) ) {
            Schema::create( 'order_edit_histories', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger( 'order_id' )->index();
                $table->unsignedBigInteger( 'user_id' )->nullable()->index();
                $table->string( 'action', 64 )->index();
                $table->string( 'field', 64 )->nullable();
                $table->text( 'old_value' )->nullable();
                $table->text( 'new_value' )->nullable();
                $table->json( 'metadata' )->nullable();
                $table->timestamps();

                $table->index( ['order_id', 'created_at'] );
            } );
        }

        if ( Schema::hasTable( 'vendor_roles' ) && ! Schema::hasColumn( 'vendor_roles', 'edit_order' ) ) {
            Schema::table( 'vendor_roles', function ( Blueprint $table ) {
                $table->integer( 'edit_order' )->nullable();
            } );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists( 'order_edit_histories' );

        if ( Schema::hasTable( 'vendor_roles' ) && Schema::hasColumn( 'vendor_roles', 'edit_order' ) ) {
            Schema::table( 'vendor_roles', function ( Blueprint $table ) {
                $table->dropColumn( 'edit_order' );
            } );
        }
    }
};
