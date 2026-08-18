<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if ( Schema::hasTable( 'subscriptions' ) && ! Schema::hasColumn( 'subscriptions', 'has_custom_domain' ) ) {
            Schema::table( 'subscriptions', function ( Blueprint $table ) {
                $table->enum( 'has_custom_domain', [ 'yes', 'no' ] )->default( 'no' )->after( 'has_website' );
            } );
        }

        if ( Schema::hasTable( 'user_subscriptions' ) && ! Schema::hasColumn( 'user_subscriptions', 'has_custom_domain' ) ) {
            Schema::table( 'user_subscriptions', function ( Blueprint $table ) {
                $table->enum( 'has_custom_domain', [ 'yes', 'no' ] )->default( 'no' )->after( 'has_website' );
            } );
        }
    }

    public function down()
    {
        if ( Schema::hasTable( 'subscriptions' ) && Schema::hasColumn( 'subscriptions', 'has_custom_domain' ) ) {
            Schema::table( 'subscriptions', function ( Blueprint $table ) {
                $table->dropColumn( 'has_custom_domain' );
            } );
        }

        if ( Schema::hasTable( 'user_subscriptions' ) && Schema::hasColumn( 'user_subscriptions', 'has_custom_domain' ) ) {
            Schema::table( 'user_subscriptions', function ( Blueprint $table ) {
                $table->dropColumn( 'has_custom_domain' );
            } );
        }
    }
};
