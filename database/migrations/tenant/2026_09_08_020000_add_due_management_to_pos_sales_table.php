<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Due management lives in pos_sale_dues (not on pos_sales).
 * Also removes due_* columns from pos_sales if an earlier version added them.
 */
return new class extends Migration {
    public function up(): void
    {
        if ( ! Schema::hasTable( 'pos_sale_dues' ) ) {
            Schema::create( 'pos_sale_dues', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger( 'pos_sales_id' )->unique();
                $table->date( 'due_date' )->nullable()->index();
                $table->text( 'due_note' )->nullable();
                $table->date( 'last_due_reminder_date' )->nullable();
                $table->string( 'last_due_reminder_type', 20 )->nullable();
                $table->timestamps();

                $table->index( ['due_date', 'last_due_reminder_type'], 'pos_sale_dues_reminder_index' );
            } );
        }

        // Rollback earlier approach that put due fields on pos_sales.
        if ( Schema::hasTable( 'pos_sales' ) ) {
            Schema::table( 'pos_sales', function ( Blueprint $table ) {
                foreach ( ['due_date', 'due_note', 'last_due_reminder_date', 'last_due_reminder_type'] as $column ) {
                    if ( Schema::hasColumn( 'pos_sales', $column ) ) {
                        $table->dropColumn( $column );
                    }
                }
            } );
        }

        if ( Schema::hasTable( 'vendor_infos' ) && ! Schema::hasColumn( 'vendor_infos', 'due_reminder_days' ) ) {
            Schema::table( 'vendor_infos', function ( Blueprint $table ) {
                $table->unsignedInteger( 'due_reminder_days' )->nullable()->default( 1 );
            } );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists( 'pos_sale_dues' );

        if ( Schema::hasTable( 'vendor_infos' ) && Schema::hasColumn( 'vendor_infos', 'due_reminder_days' ) ) {
            Schema::table( 'vendor_infos', function ( Blueprint $table ) {
                $table->dropColumn( 'due_reminder_days' );
            } );
        }
    }
};
