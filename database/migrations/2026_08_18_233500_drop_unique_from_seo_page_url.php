<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if ( ! Schema::hasTable( 'seo' ) ) {
            return;
        }

        $indexes = DB::select( "SHOW INDEX FROM `seo` WHERE Column_name = 'page_url' AND Non_unique = 0" );

        foreach ( $indexes as $index ) {
            Schema::table( 'seo', function ( Blueprint $table ) use ( $index ) {
                $table->dropUnique( $index->Key_name );
            } );
        }
    }

    public function down()
    {
        if ( ! Schema::hasTable( 'seo' ) ) {
            return;
        }

        Schema::table( 'seo', function ( Blueprint $table ) {
            $table->unique( 'page_url' );
        } );
    }
};
