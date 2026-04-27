<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if ( !Schema::hasTable( 'withdraws' ) || Schema::hasColumn( 'withdraws', 'tenant_id' ) ) {
            return;
        }

        Schema::table( 'withdraws', function ( Blueprint $table ) {
            $table->string( 'tenant_id' )->nullable()->after( 'user_id' );
        } );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ( !Schema::hasTable( 'withdraws' ) || !Schema::hasColumn( 'withdraws', 'tenant_id' ) ) {
            return;
        }

        Schema::table( 'withdraws', function ( Blueprint $table ) {
            $table->dropColumn( 'tenant_id' );
        } );
    }
};
