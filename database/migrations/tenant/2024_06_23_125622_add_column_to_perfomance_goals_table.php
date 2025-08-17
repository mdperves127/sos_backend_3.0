<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up() {
        Schema::table( 'perfomance_goals', function ( Blueprint $table ) {
            $table->integer( 'conversion_location_id' )->nullable();
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::table( 'perfomance_goals', function ( Blueprint $table ) {
            $table->dropColumn( 'conversion_location_id' );
        } );
    }
};
