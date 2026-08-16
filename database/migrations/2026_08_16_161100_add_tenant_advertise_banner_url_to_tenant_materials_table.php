<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table( 'tenant_materials', function ( Blueprint $table ) {
            $table->text( 'tenant_advertise_banner_url' )->nullable()->after( 'tenant_advertise_banner' );
        } );
    }

    public function down(): void
    {
        Schema::table( 'tenant_materials', function ( Blueprint $table ) {
            $table->dropColumn( 'tenant_advertise_banner_url' );
        } );
    }
};
