<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ( ! Schema::hasTable( 'subscriptions' ) || Schema::hasColumn( 'subscriptions', 'is_custom' ) ) {
            return;
        }

        Schema::table( 'subscriptions', function ( Blueprint $table ) {
            $table->boolean( 'is_custom' )->default( false )->after( 'plan_type' );
        } );
    }

    public function down(): void
    {
        if ( ! Schema::hasTable( 'subscriptions' ) || ! Schema::hasColumn( 'subscriptions', 'is_custom' ) ) {
            return;
        }

        Schema::table( 'subscriptions', function ( Blueprint $table ) {
            $table->dropColumn( 'is_custom' );
        } );
    }
};
