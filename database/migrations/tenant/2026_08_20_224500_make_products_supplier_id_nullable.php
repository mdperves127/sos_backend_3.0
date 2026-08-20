<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if ( ! Schema::hasColumn( 'products', 'supplier_id' ) ) {
            Schema::table( 'products', function ( Blueprint $table ) {
                $table->integer( 'supplier_id' )->nullable()->after( 'subcategory_id' );
            } );

            return;
        }

        Schema::table( 'products', function ( Blueprint $table ) {
            $table->integer( 'supplier_id' )->nullable()->change();
        } );
    }

    public function down(): void {
        // Intentionally left blank: do not force NOT NULL on rollback.
    }
};
