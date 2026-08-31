<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create( 'tenant_addons', function ( Blueprint $table ) {
            $table->id();
            $table->string( 'tenant_id' );
            $table->foreignId( 'addon_id' )->constrained( 'addons' )->cascadeOnDelete();
            $table->unsignedBigInteger( 'user_id' )->nullable();
            $table->string( 'addon_name' );
            $table->enum( 'addon_type', ['membership', 'system'] );
            $table->enum( 'value_type', ['number', 'string', 'yes', 'no'] );
            $table->string( 'value' )->nullable();
            $table->decimal( 'price_paid', 12, 2 )->default( 0 );
            $table->string( 'payment_method' )->nullable();
            $table->string( 'trxid' )->nullable();
            $table->enum( 'status', ['pending', 'active', 'inactive', 'cancelled'] )->default( 'pending' );
            $table->timestamp( 'activated_at' )->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index( ['tenant_id', 'status'] );
            $table->index( ['tenant_id', 'addon_id'] );
        } );
    }

    public function down(): void
    {
        Schema::dropIfExists( 'tenant_addons' );
    }
};
