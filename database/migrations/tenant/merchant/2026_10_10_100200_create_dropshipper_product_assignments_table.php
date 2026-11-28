<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if ( Schema::hasTable( 'dropshipper_product_assignments' ) ) {
            return;
        }

        Schema::create( 'dropshipper_product_assignments', function ( Blueprint $table ) {
            $table->id();
            $table->string( 'dropshipper_tenant_id' );
            $table->string( 'assignable_type', 20 ); // product | category
            $table->unsignedBigInteger( 'product_id' )->nullable();
            $table->unsignedBigInteger( 'category_id' )->nullable();
            // "product:12" / "category:5" — lets one unique index cover both types.
            $table->string( 'target_key', 64 );
            $table->string( 'status', 20 )->default( 'active' ); // active | revoked
            $table->unsignedBigInteger( 'assigned_by' )->nullable();
            $table->unsignedBigInteger( 'revoked_by' )->nullable();
            $table->timestamp( 'revoked_at' )->nullable();
            $table->timestamps();

            $table->unique( ['dropshipper_tenant_id', 'target_key'], 'dpa_tenant_target_unique' );
            $table->index( ['dropshipper_tenant_id', 'status'], 'dpa_tenant_status_index' );
            $table->index( ['product_id', 'status'], 'dpa_product_status_index' );
            $table->index( ['category_id', 'status'], 'dpa_category_status_index' );
        } );
    }

    public function down()
    {
        Schema::dropIfExists( 'dropshipper_product_assignments' );
    }
};
