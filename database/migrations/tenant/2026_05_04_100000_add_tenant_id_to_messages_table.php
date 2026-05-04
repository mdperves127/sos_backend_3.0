<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table( 'messages', function ( Blueprint $table ) {
            if ( !Schema::hasColumn( 'messages', 'tenant_id' ) ) {
                $table->string( 'tenant_id', 191 )->nullable()->after( 'id' )->index();
            }
        } );

        if ( function_exists( 'tenant' ) && tenant() ) {
            DB::connection( 'tenant' )->table( 'messages' )
                ->whereNull( 'tenant_id' )
                ->update( ['tenant_id' => tenant()->id] );
        }
    }

    public function down(): void {
        Schema::table( 'messages', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'messages', 'tenant_id' ) ) {
                $table->dropColumn( 'tenant_id' );
            }
        } );
    }
};
