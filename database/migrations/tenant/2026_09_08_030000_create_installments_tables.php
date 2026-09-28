<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POS installment plans — separate from pos_sale_dues due management.
 */
return new class extends Migration {
    public function up(): void
    {
        if ( ! Schema::hasTable( 'installments' ) ) {
            Schema::create( 'installments', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger( 'pos_sales_id' )->index();
                $table->unsignedInteger( 'installment_number' );
                $table->decimal( 'amount', 12, 2 );
                $table->decimal( 'paid_amount', 12, 2 )->default( 0 );
                $table->decimal( 'remaining_amount', 12, 2 );
                $table->date( 'due_date' )->index();
                $table->string( 'status', 20 )->default( 'unpaid' )->index(); // unpaid|partial|paid
                $table->date( 'last_reminder_date' )->nullable();
                $table->string( 'last_reminder_type', 20 )->nullable(); // before|due|overdue
                $table->timestamps();

                $table->unique( ['pos_sales_id', 'installment_number'], 'installments_sale_number_unique' );
                $table->index( ['due_date', 'status'], 'installments_due_status_index' );
            } );
        }

        if ( ! Schema::hasTable( 'installment_payments' ) ) {
            Schema::create( 'installment_payments', function ( Blueprint $table ) {
                $table->id();
                $table->unsignedBigInteger( 'installment_id' )->index();
                $table->unsignedBigInteger( 'pos_sales_id' )->nullable()->index();
                $table->unsignedBigInteger( 'customer_payment_id' )->nullable()->index();
                $table->unsignedBigInteger( 'payment_method_id' )->nullable();
                $table->unsignedBigInteger( 'user_id' )->nullable(); // staff who collected
                $table->decimal( 'amount', 12, 2 );
                $table->dateTime( 'paid_at' )->nullable();
                $table->string( 'note', 1000 )->nullable();
                $table->timestamps();
            } );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists( 'installment_payments' );
        Schema::dropIfExists( 'installments' );
    }
};
