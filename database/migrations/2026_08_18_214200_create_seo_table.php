<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if ( Schema::hasTable( 'seo' ) ) {
            return;
        }

        Schema::create( 'seo', function ( Blueprint $table ) {
            $table->id();
            $table->string( 'page_url' )->unique();
            $table->string( 'seo_title' );
            $table->text( 'seo_value' )->nullable();
            $table->timestamps();
        } );
    }

    public function down()
    {
        Schema::dropIfExists( 'seo' );
    }
};
