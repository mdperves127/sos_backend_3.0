<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table( 'categories', function ( Blueprint $table ) {
            if ( ! Schema::hasColumn( 'categories', 'auto_approve_dropshipper' ) ) {
                $table->boolean( 'auto_approve_dropshipper' )->default( false );
            }
        } );
    }

    public function down()
    {
        Schema::table( 'categories', function ( Blueprint $table ) {
            if ( Schema::hasColumn( 'categories', 'auto_approve_dropshipper' ) ) {
                $table->dropColumn( 'auto_approve_dropshipper' );
            }
        } );
    }
};
