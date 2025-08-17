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
        Schema::create( 'vendor_roles', function ( Blueprint $table ) {
            $table->id();
            // $table->string( 'name' )->nullable();
            // $table->integer( 'products' )->nullable();
            // $table->integer( 'add_product' )->nullable();
            // $table->integer( 'all_product' )->nullable();
            // $table->integer( 'active_product' )->nullable();
            // $table->integer( 'pending_product' )->nullable();
            // $table->integer( 'edit_product' )->nullable();
            // $table->integer( 'reject_product' )->nullable();

            // $table->integer( 'warehouse' )->nullable();
            // $table->integer( 'all_warehouse' )->nullable();
            // $table->integer( 'add_warehouse' )->nullable();
            // $table->integer( 'edit_warehouse' )->nullable();
            // $table->integer( 'delete_warehouse' )->nullable();

            // $table->integer( 'unit' )->nullable();
            // $table->integer( 'all_unit' )->nullable();
            // $table->integer( 'add_unit' )->nullable();
            // $table->integer( 'edit_unit' )->nullable();
            // $table->integer( 'delete_unit' )->nullable();

            // $table->integer( 'color' )->nullable();
            // $table->integer( 'all_color' )->nullable();
            // $table->integer( 'add_color' )->nullable();
            // $table->integer( 'edit_color' )->nullable();
            // $table->integer( 'delete_color' )->nullable();

            // $table->integer( 'variation' )->nullable();
            // $table->integer( 'all_variation' )->nullable();
            // $table->integer( 'add_variation' )->nullable();
            // $table->integer( 'edit_variation' )->nullable();
            // $table->integer( 'delete_variation' )->nullable();

            // $table->integer( 'order' )->nullable();
            // $table->integer( 'add_order' )->nullable();
            // $table->integer( 'all_order' )->nullable();
            // $table->integer( 'hold_order' )->nullable();
            // $table->integer( 'receive_order' )->nullable();
            // $table->integer( 'delivery_processing' )->nullable();
            // $table->integer( 'delivery_product' )->nullable();
            // $table->integer( 'cancel_order' )->nullable();

            // $table->integer( 'customer' )->nullable();
            // $table->integer( 'all_customer' )->nullable();
            // $table->integer( 'add_customer' )->nullable();
            // $table->integer( 'edit_customer' )->nullable();
            // $table->integer( 'delete_customer' )->nullable();

            // $table->integer( 'pos_sale' )->nullable();
            // $table->integer( 'add_pos_sale' )->nullable();
            // $table->integer( 'all_pos_sale' )->nullable();
            // $table->integer( 'view_pos_sale' )->nullable();
            // $table->integer( 'invoice_pos_sale' )->nullable();
            // $table->integer( 'return_pos_sale' )->nullable();
            // $table->integer( 'exchange_pos_sale' )->nullable();
            // $table->integer( 'payment_pos_sale' )->nullable();
            // $table->integer( 'payment_history_pos_sale' )->nullable();

            // $table->integer( 'supplier' )->nullable();
            // $table->integer( 'all_supplier' )->nullable();
            // $table->integer( 'add_supplier' )->nullable();
            // $table->integer( 'edit_supplier' )->nullable();
            // $table->integer( 'delete_supplier' )->nullable();

            // $table->integer( 'purchase' )->nullable();
            // $table->integer( 'add_purchase' )->nullable();
            // $table->integer( 'all_purchase' )->nullable();
            // $table->integer( 'view_purchase' )->nullable();
            // $table->integer( 'invoice_purchase' )->nullable();
            // $table->integer( 'return_purchase' )->nullable();
            // $table->integer( 'payment_purchase' )->nullable();
            // $table->integer( 'payment_history_purchase' )->nullable();

            // $table->integer( 'barcode' )->nullable();
            // $table->integer( 'barcode_generate' )->nullable();
            // $table->integer( 'barcode_manage' )->nullable();

            // $table->integer( 'setting' )->nullable();

            // $table->integer( 'source' )->nullable();
            // $table->integer( 'all_source' )->nullable();
            // $table->integer( 'add_source' )->nullable();
            // $table->integer( 'edit_source' )->nullable();
            // $table->integer( 'delete_source' )->nullable();

            // $table->integer( 'payment_method' )->nullable();
            // $table->integer( 'all_payment_method' )->nullable();
            // $table->integer( 'add_payment_method' )->nullable();
            // $table->integer( 'edit_payment_method' )->nullable();
            // $table->integer( 'delete_payment_method' )->nullable();

            // $table->integer( 'affiliate_request' )->nullable();
            // $table->integer( 'all_request' )->nullable();
            // $table->integer( 'active_request' )->nullable();
            // $table->integer( 'pending_request' )->nullable();
            // $table->integer( 'reject_request' )->nullable();
            // $table->integer( 'expired_request' )->nullable();

            // $table->integer( 'return_list' )->nullable();
            // $table->integer( 'purchase_return' )->nullable();
            // $table->integer( 'sale_return' )->nullable();
            // $table->integer( 'add_wastage' )->nullable();
            // $table->integer( 'all_wastage' )->nullable();

            // $table->integer( 'report' )->nullable();
            // $table->integer( 'stock_report' )->nullable();
            // $table->integer( 'sales_report' )->nullable();
            // $table->integer( 'due_sales_report' )->nullable();
            // $table->integer( 'purchase_report' )->nullable();
            // $table->integer( 'warehouse_report' )->nullable();

            // $table->integer( 'service_and_order' )->nullable();
            // $table->integer( 'create_service' )->nullable();
            // $table->integer( 'all_service' )->nullable();
            // $table->integer( 'edit_service' )->nullable();
            // $table->integer( 'delete_service' )->nullable();
            // $table->integer( 'service_order' )->nullable();

            // $table->integer( 'coupon' )->nullable();
            // $table->integer( 'membership' )->nullable();
            // $table->integer( 'advertiser' )->nullable();

            // $table->integer( 'purchase_service' )->nullable();
            // $table->integer( 'all_service_order' )->nullable();
            // $table->integer( 'pending_service_order' )->nullable();
            // $table->integer( 'progress_service_order' )->nullable();
            // $table->integer( 'hold_service_order' )->nullable();
            // $table->integer( 'cancel_service_order' )->nullable();

            // $table->integer( 'balance' )->nullable();
            // $table->integer( 'recharge' )->nullable();
            // $table->integer( 'withdraw' )->nullable();
            // $table->integer( 'recharge_history' )->nullable();

            // $table->integer( 'create_support' )->nullable();
            // $table->integer( 'all_support' )->nullable();
            // $table->integer( 'download_file' )->nullable();
            // $table->integer( 'delete_support' )->nullable();

            // $table->integer( 'chat' )->nullable();

            // $table->integer( 'add_role' )->nullable();
            // $table->integer( 'all_role' )->nullable();
            // $table->integer( 'edit_role' )->nullable();
            // $table->integer( 'delete_role' )->nullable();

            // $table->integer( 'add_employee' )->nullable();
            // $table->integer( 'all_employee' )->nullable();
            // $table->integer( 'edit_employee' )->nullable();
            // $table->integer( 'delete_employee' )->nullable();

            $table->string( 'name' )->nullable();
            $table->string( 'user_id' )->nullable();
            $table->unsignedBigInteger( 'vendor_id' )->nullable();
            $table->integer( 'products' )->nullable();
            $table->integer( 'add_product' )->nullable();
            $table->integer( 'all_product' )->nullable();
            $table->integer( 'active_product' )->nullable();
            $table->integer( 'pending_product' )->nullable();
            $table->integer( 'edit_product' )->nullable();
            $table->integer( 'reject_product' )->nullable();

            $table->integer( 'warehouse' )->nullable();

            $table->integer( 'unit' )->nullable();

            $table->integer( 'color' )->nullable();

            $table->integer( 'variation' )->nullable();

            $table->integer( 'order' )->nullable();
            $table->integer( 'add_order' )->nullable();
            $table->integer( 'all_order' )->nullable();
            $table->integer( 'hold_order' )->nullable();
            $table->integer( 'pending_order' )->nullable();
            $table->integer( 'receive_order' )->nullable();
            $table->integer( 'delivery_processing' )->nullable();
            $table->integer( 'delivery_order' )->nullable();
            $table->integer( 'cancel_order' )->nullable();

            $table->integer( 'customer' )->nullable();

            $table->integer( 'pos_sale' )->nullable();
            $table->integer( 'add_pos_sale' )->nullable();
            $table->integer( 'all_pos_sale' )->nullable();
            $table->integer( 'payment_history_pos_sale' )->nullable();

            $table->integer( 'supplier' )->nullable();

            $table->integer( 'purchase' )->nullable();
            $table->integer( 'add_purchase' )->nullable();
            $table->integer( 'all_purchase' )->nullable();
            $table->integer( 'payment_history_purchase' )->nullable();

            $table->integer( 'barcode' )->nullable();
            $table->integer( 'barcode_generate' )->nullable();
            $table->integer( 'barcode_manage' )->nullable();

            $table->integer( 'setting' )->nullable();

            $table->integer( 'source' )->nullable();

            $table->integer( 'payment_method' )->nullable();

            $table->integer( 'affiliate_request' )->nullable();
            $table->integer( 'all_request' )->nullable();
            $table->integer( 'active_request' )->nullable();
            $table->integer( 'pending_request' )->nullable();
            $table->integer( 'reject_request' )->nullable();
            $table->integer( 'expired_request' )->nullable();

            $table->integer( 'return_list' )->nullable();
            $table->integer( 'purchase_return' )->nullable();
            $table->integer( 'sale_return' )->nullable();
            $table->integer( 'add_wastage' )->nullable();
            $table->integer( 'all_wastage' )->nullable();

            $table->integer( 'report' )->nullable();
            $table->integer( 'stock_report' )->nullable();
            $table->integer( 'sales_report' )->nullable();
            $table->integer( 'due_sales_report' )->nullable();
            $table->integer( 'purchase_report' )->nullable();
            $table->integer( 'warehouse_report' )->nullable();

            $table->integer( 'service_and_order' )->nullable();
            $table->integer( 'create_service' )->nullable();
            $table->integer( 'all_service' )->nullable();
            $table->integer( 'service_order' )->nullable();

            $table->integer( 'coupon' )->nullable();
            $table->integer( 'membership' )->nullable();
            $table->integer( 'advertiser' )->nullable();

            $table->integer( 'purchase_service' )->nullable();
            $table->integer( 'all_service_order' )->nullable();
            $table->integer( 'pending_service_order' )->nullable();
            $table->integer( 'progress_service_order' )->nullable();
            $table->integer( 'hold_service_order' )->nullable();
            $table->integer( 'cancel_service_order' )->nullable();

            $table->integer( 'balance' )->nullable();
            $table->integer( 'recharge' )->nullable();
            $table->integer( 'withdraw' )->nullable();
            $table->integer( 'recharge_history' )->nullable();

            $table->integer( 'create_support' )->nullable();
            $table->integer( 'all_support' )->nullable();

            $table->integer( 'chat' )->nullable();

            $table->integer( 'employee' )->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->foreign( 'vendor_id' )->references( 'id' )->on( 'users' )->onDelete( 'cascade' );
        } );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down() {
        Schema::dropIfExists( 'vendor_roles' );
    }
};
