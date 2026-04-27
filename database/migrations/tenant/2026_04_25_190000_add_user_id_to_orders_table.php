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
        if ( !Schema::hasTable( 'orders' ) || Schema::hasColumn( 'orders', 'user_id' ) ) {
            return;
        }

        Schema::table( 'orders', function ( Blueprint $table ) {
            $table->unsignedBigInteger( 'user_id' )->nullable()->after( 'id' );
        } );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left empty because the base tenant orders migration
        // in this workspace already declares user_id for fresh installs.
    }
};
