<?php

declare ( strict_types = 1 );

use App\Http\Controllers\AdvertiseController;
use App\Http\Controllers\API\ColorController;
use App\Http\Controllers\API\CouponListController;
use App\Http\Controllers\API\CouponRequestController;
use App\Http\Controllers\API\ServiceOrderController;
use App\Http\Controllers\API\SizeController;
use App\Http\Controllers\API\Vendor\BrandController as VendorBrandController;
use App\Http\Controllers\API\Vendor\CategoryController;
use App\Http\Controllers\API\Vendor\CourierCredentialController;
use App\Http\Controllers\API\Vendor\CustomerController;
use App\Http\Controllers\API\Vendor\DamageController;
use App\Http\Controllers\API\Vendor\DeliveryAndPickupAddressController;
use App\Http\Controllers\API\Vendor\DeliveryChargeController;
use App\Http\Controllers\API\Vendor\DeliveryCompanyController;
use App\Http\Controllers\API\Vendor\OrderController as VendorOrderController;
use App\Http\Controllers\API\Vendor\PaymentMethodController;
use App\Http\Controllers\API\Vendor\PosSaleReturnController;
use App\Http\Controllers\API\Vendor\ProductManageController;
use App\Http\Controllers\API\Vendor\ProductPosSaleController;
use App\Http\Controllers\API\Vendor\ProductPurchaseController;
use App\Http\Controllers\API\Vendor\ProductStatusController;
use App\Http\Controllers\API\Vendor\ProfileController;
use App\Http\Controllers\API\Vendor\ReportController;
use App\Http\Controllers\API\Vendor\SaleOrderResourceController;
use App\Http\Controllers\API\Vendor\SubCategoryController;
use App\Http\Controllers\API\Vendor\SubUnitController;
use App\Http\Controllers\API\Vendor\SupplierController;
use App\Http\Controllers\API\Vendor\SupplierProductReturnController;
use App\Http\Controllers\API\Vendor\UnitController;
use App\Http\Controllers\API\Vendor\VendorController;
use App\Http\Controllers\API\Vendor\WarehouseController;
use App\Http\Controllers\API\Vendor\WoocommerceCredentialController as WooCommerceCredentialController;
use App\Http\Controllers\API\Vendor\WoocommerceOrderController as WooCommerceOrderController;
use App\Http\Controllers\API\Vendor\WoocommerceProductController as WooCommerceProductController;
use App\Http\Controllers\Tenant\TenantAuthController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use App\Http\Controllers\Tenant\RechargeController;
use App\Http\Controllers\Tenant\WithdrawController;
use App\Http\Controllers\Tenant\BankController;
use App\Http\Controllers\Tenant\SupportBoxController;

Route::middleware( [
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
] )->group( function () {
    Route::get( '/', function () {
        return response()->json( [
            'message'   => 'Tenant subdomain is working!',
            'tenant_id' => tenant( 'id' ),
            'domain'    => request()->getHost(),
            'timestamp' => now(),
        ] );
    } );
    // Public tenant routes
    Route::post( '/auth/login', [TenantAuthController::class, 'login'] );
    Route::post( '/auth/register', [TenantAuthController::class, 'register'] );

    // Protected tenant routes
    Route::middleware( 'tenantAuth' )->group( function () {

        Route::prefix( 'tenant-auth' )->group( function () {
            Route::post( '/logout', [TenantAuthController::class, 'logout'] );
            Route::put( '/profile', [TenantAuthController::class, 'updateProfile'] );
            Route::get( '/profile/info', [TenantAuthController::class, 'profileInfo'] );
            Route::put( '/change-password', [TenantAuthController::class, 'changePassword'] );
        } );

        //Vendor Routes
        Route::prefix( 'tenant-profile' )->group( function () {
            Route::get( '/', [ProfileController::class, 'VendorProfile'] );
            Route::post( 'update', [ProfileController::class, 'VendorUpdateProfile'] );
        } );

        //vendor product
        Route::prefix( 'tenant-product' )->group( function () {
            Route::get( '/count-data', [ProductManageController::class, 'VendorProductCount'] );
            Route::get( 'create', [ProductManageController::class, 'create'] );
            Route::post( 'store', [ProductManageController::class, 'VendorProductStore'] );
            Route::get( 'edit-count', [ProductManageController::class, 'vendorProductEditCount'] );
            Route::get( 'edit/{id}', [ProductManageController::class, 'VendorProductEdit'] );
            Route::post( 'update/{id}', [ProductManageController::class, 'VendorUpdateProduct'] );
            Route::delete( 'delete-image/{id}', [ProductManageController::class, 'VendorDeleteImage'] );
            Route::delete( 'delete/{id}', [ProductManageController::class, 'VendorDelete'] );
            Route::get( '/{status?}', [ProductManageController::class, 'VendorProduct'] );

        } );

        Route::prefix( 'tenant-product-order' )->group( function () {
            Route::get( 'all-orders', [VendorOrderController::class, 'AllOrders'] );
            Route::get( 'pending-orders', [VendorOrderController::class, 'pendingOrders'] );
            Route::get( 'progress-orders', [VendorOrderController::class, 'ProgressOrders'] );
            Route::get( 'product-processing', [VendorOrderController::class, 'ProductProcessing'] );
            Route::get( 'order-ready', [VendorOrderController::class, 'OrderReady'] );
            Route::get( 'received-orders', [VendorOrderController::class, 'receivedOrders'] );
            Route::get( 'delivered-orders', [VendorOrderController::class, 'DeliveredOrders'] );
            Route::get( 'cancel-orders', [VendorOrderController::class, 'CanceldOrders'] );
            Route::get( 'hold-orders', [VendorOrderController::class, 'HoldOrders'] );
            Route::get( 'order-count', [VendorOrderController::class, 'orderCount'] );
            Route::get( 'order-return', [VendorOrderController::class, 'orderReturn'] );
        } );

        Route::get( 'vendor-all-category', [VendorController::class, 'AllCategory'] );
        Route::get( 'vendor-all-subcategory', [VendorController::class, 'AllSubCategory'] );
        // Route::get('vendor-all/brand', [VendorController::class, 'AllBrand']);
        Route::get( 'vendor-all-color', [VendorController::class, 'AllColor'] );
        Route::get( 'vendor-all-size', [VendorController::class, 'AllSize'] );

        Route::get( 'vendor/balance/request', [ProductStatusController::class, 'VendorBalanceRequest'] );
        Route::post( 'vendor/request/sent', [ProductStatusController::class, 'VendorRequestSent'] );

        Route::get( 'vendor-product-approval/{id}', [ProductStatusController::class, 'approval'] );
        Route::get( 'vendor-product-reject/{id}', [ProductStatusController::class, 'reject'] );
        Route::get( 'vendor-all/product-accepted/{id}', [ProductStatusController::class, 'Accepted'] );
        Route::get( 'vendor-product-status-count', [ProductStatusController::class, 'statusCount'] );

        //tenant Brand
        Route::prefix( 'tenant-brand' )->group( function () {
            Route::get( '/', [VendorBrandController::class, 'index'] );
            Route::get( 'active', [VendorBrandController::class, 'active'] );
            Route::post( 'store', [VendorBrandController::class, 'store'] );
            Route::get( 'edit/{id}', [VendorBrandController::class, 'edit'] );
            Route::post( 'update/{id}', [VendorBrandController::class, 'update'] );
            Route::delete( 'delete/{id}', [VendorBrandController::class, 'destroy'] );
        } );

        // tenant Category
        Route::prefix( 'tenant-category' )->group( function () {
            Route::get( '/', [CategoryController::class, 'index'] );
            Route::get( 'active', [CategoryController::class, 'active'] );
            Route::post( 'store', [CategoryController::class, 'store'] );
            Route::get( 'edit/{id}', [CategoryController::class, 'edit'] );
            Route::put( 'update/{id}', [CategoryController::class, 'update'] );
            Route::put( 'status/{id}', [CategoryController::class, 'status'] );
            Route::delete( 'delete/{id}', [CategoryController::class, 'destroy'] );
        } );

        //tenant Sub Category
        Route::prefix( 'tenant-sub-category' )->group( function () {
            Route::get( '/', [SubCategoryController::class, 'index'] );
            Route::post( 'store', [SubCategoryController::class, 'store'] );
            Route::get( 'edit/{id}', [SubCategoryController::class, 'edit'] );
            Route::put( 'update/{id}', [SubCategoryController::class, 'update'] );
            Route::put( 'status/{id}', [SubCategoryController::class, 'status'] );
            Route::delete( 'delete/{id}', [SubCategoryController::class, 'destroy'] );
        } );

        //color
        Route::prefix( 'tenant-color' )->group( function () {
            Route::get( '/', [ColorController::class, 'index'] );
            Route::post( 'store', [ColorController::class, 'store'] );
            Route::get( 'edit/{id}', [ColorController::class, 'edit'] );
            Route::post( 'update/{id}', [ColorController::class, 'update'] );
            Route::delete( 'delete/{id}', [ColorController::class, 'destroy'] );
        } );

        //size route
        Route::prefix( 'tenant-variant' )->group( function () {
            Route::get( '/', [SizeController::class, 'index'] );
            Route::post( 'store', [SizeController::class, 'store'] );
            Route::get( 'edit/{id}', [SizeController::class, 'edit'] );
            Route::post( 'update/{id}', [SizeController::class, 'update'] );
            Route::delete( 'delete/{id}', [SizeController::class, 'destroy'] );
        } );

        //Units Route
        Route::prefix( 'tenant-unit' )->group( function () {
            Route::get( '/', [UnitController::class, 'index'] );
            Route::post( 'store', [UnitController::class, 'store'] );
            Route::get( 'edit/{id}', [UnitController::class, 'edit'] );
            Route::post( 'update/{id}', [UnitController::class, 'update'] );
            Route::delete( 'delete/{id}', [UnitController::class, 'destroy'] );
            Route::get( 'status/{id}', [UnitController::class, 'status'] );
        } );

        //Sub-units Route
        Route::prefix( 'tenant-sub-unit' )->group( function () {
            Route::get( '/', [SubUnitController::class, 'index'] );
            Route::post( 'store', [SubUnitController::class, 'store'] );
            Route::get( 'edit/{id}', [SubUnitController::class, 'edit'] );
            Route::post( 'update/{id}', [SubUnitController::class, 'update'] );
            Route::delete( 'delete/{id}', [SubUnitController::class, 'destroy'] );
            Route::get( 'status/{id}', [SubUnitController::class, 'status'] );
        } );

        //Ware house Route
        Route::prefix( 'tenant-warehouse' )->group( function () {
            Route::get( '/', [WarehouseController::class, 'index'] );
            Route::post( 'store', [WarehouseController::class, 'store'] );
            Route::get( 'edit/{id}', [WarehouseController::class, 'edit'] );
            Route::post( 'update/{id}', [WarehouseController::class, 'update'] );
            Route::delete( 'delete/{id}', [WarehouseController::class, 'destroy'] );
            Route::get( 'status/{id}', [WarehouseController::class, 'status'] );
        } );

        //Suppler Route
        Route::prefix( 'tenant-supplier' )->group( function () {
            Route::get( '/', [SupplierController::class, 'index'] );
            Route::post( 'store', [SupplierController::class, 'store'] );
            Route::get( 'edit/{id}', [SupplierController::class, 'edit'] );
            Route::post( 'update/{id}', [SupplierController::class, 'update'] );
            Route::delete( 'delete/{id}', [SupplierController::class, 'destroy'] );
            Route::get( 'status/{id}', [SupplierController::class, 'status'] );
        } );

        //Customer Route
        Route::prefix( 'tenant-customer' )->group( function () {
            Route::get( '/', [CustomerController::class, 'index'] );
            Route::post( 'store', [CustomerController::class, 'store'] );
            Route::get( 'edit/{id}', [CustomerController::class, 'edit'] );
            Route::post( 'update/{id}', [CustomerController::class, 'update'] );
            Route::delete( 'delete/{id}', [CustomerController::class, 'destroy'] );
            Route::get( 'status/{id}', [CustomerController::class, 'status'] );
        } );

        Route::prefix( 'tenant-order-source' )->group( function () {
            Route::get( '/', [SaleOrderResourceController::class, 'index'] );
            Route::post( 'store', [SaleOrderResourceController::class, 'store'] );
            Route::get( 'edit/{id}', [SaleOrderResourceController::class, 'edit'] );
            Route::post( 'update/{id}', [SaleOrderResourceController::class, 'update'] );
            Route::delete( 'delete/{id}', [SaleOrderResourceController::class, 'destroy'] );
            Route::get( 'status/{id}', [SaleOrderResourceController::class, 'status'] );
        } );

        //Delivery charge Route
        Route::prefix( 'tenant-delivery-charge' )->group( function () {
            Route::get( '/', [DeliveryChargeController::class, 'index'] );
            Route::post( 'store', [DeliveryChargeController::class, 'store'] );
            Route::get( 'edit/{id}', [DeliveryChargeController::class, 'edit'] );
            Route::post( 'update/{id}', [DeliveryChargeController::class, 'update'] );
            Route::delete( 'delete/{id}', [DeliveryChargeController::class, 'destroy'] );
            Route::get( 'status/{id}', [DeliveryChargeController::class, 'status'] );
        } );

        //Delivery company Route
        Route::prefix( 'delivery-company' )->group( function () {
            Route::get( '/', [DeliveryCompanyController::class, 'index'] );
            Route::post( 'store', [DeliveryCompanyController::class, 'store'] );
            Route::get( 'edit/{id}', [DeliveryCompanyController::class, 'edit'] );
            Route::post( 'update/{id}', [DeliveryCompanyController::class, 'update'] );
            Route::delete( 'delete/{id}', [DeliveryCompanyController::class, 'destroy'] );
            Route::get( 'status/{id}', [DeliveryCompanyController::class, 'status'] );
            Route::get( 'list', [DeliveryCompanyController::class, 'companyList'] );
        } );

        //Delivery company Route
        Route::prefix( 'tenant-delivery-and-pickup-address' )->group( function () {
            Route::get( '/', [DeliveryAndPickupAddressController::class, 'index'] );
            Route::post( 'store', [DeliveryAndPickupAddressController::class, 'store'] );
            Route::get( 'edit/{id}', [DeliveryAndPickupAddressController::class, 'edit'] );
            Route::post( 'update/{id}', [DeliveryAndPickupAddressController::class, 'update'] );
            Route::delete( 'delete/{id}', [DeliveryAndPickupAddressController::class, 'destroy'] );
            Route::get( 'status/{id}', [DeliveryAndPickupAddressController::class, 'status'] );
        } );

        //courier-credential Route
        Route::prefix( 'tenant-courier-credential' )->group( function () {
            Route::get( '/', [CourierCredentialController::class, 'index'] );
            Route::post( 'store', [CourierCredentialController::class, 'store'] );
            Route::get( 'edit/{id}', [CourierCredentialController::class, 'edit'] );
            Route::post( 'update/{id}', [CourierCredentialController::class, 'update'] );
            Route::delete( 'delete/{id}', [CourierCredentialController::class, 'destroy'] );
            Route::get( 'status/{id}', [CourierCredentialController::class, 'status'] );
            Route::get( 'default/{id}', [CourierCredentialController::class, 'default'] );
        } );

        //Woo-commerce-credential Route
        Route::prefix( 'tenant-woo-commerce-credential' )->group( function () {
            Route::get( '/', [WooCommerceCredentialController::class, 'index'] );
            Route::post( 'store', [WooCommerceCredentialController::class, 'store'] );
            Route::get( 'edit/{id}', [WooCommerceCredentialController::class, 'edit'] );
            Route::post( 'update/{id}', [WooCommerceCredentialController::class, 'update'] );
            Route::delete( 'delete/{id}', [WooCommerceCredentialController::class, 'destroy'] );
            Route::get( 'status/{id}', [WooCommerceCredentialController::class, 'status'] );
            Route::get( 'default/{id}', [WooCommerceCredentialController::class, 'default'] );
        } );

        //Woo-commerce-credential Route
        Route::prefix( 'woo-commerce-order' )->group( function () {
            Route::get( '/', [WooCommerceOrderController::class, 'index'] );
            Route::post( 'store', [WooCommerceOrderController::class, 'store'] );
            Route::post( 'delivery/{id}', [WooCommerceOrderController::class, 'wcOrderDelivery'] );
            Route::get( 'order-status/{id}/{status}', [WooCommerceOrderController::class, 'wcOrderStatusUpdate'] );
        } );

        Route::prefix( 'woo-commerce-product' )->group( function () {
            Route::get( '/', [WooCommerceProductController::class, 'index'] );
            Route::post( 'store', [WooCommerceProductController::class, 'wcProductStore'] );
        } );

        Route::prefix( 'tenant-payment-method' )->group( function () {
            Route::get( '/', [PaymentMethodController::class, 'index'] );
            Route::post( 'store', [PaymentMethodController::class, 'store'] );
            Route::get( 'edit/{id}', [PaymentMethodController::class, 'edit'] );
            Route::post( 'update/{id}', [PaymentMethodController::class, 'update'] );
            Route::delete( 'delete/{id}', [PaymentMethodController::class, 'destroy'] );
            Route::get( 'status/{id}', [PaymentMethodController::class, 'status'] );
        } );

        //Purchase Route
        Route::prefix( 'tenant-product-purchase' )->group( function () {
            Route::get( '/', [ProductPurchaseController::class, 'index'] );
            Route::get( 'create', [ProductPurchaseController::class, 'create'] );
            Route::post( 'store', [ProductPurchaseController::class, 'store'] );
            Route::get( 'show/{id}', [ProductPurchaseController::class, 'show'] );
            Route::get( 'edit/{id}', [ProductPurchaseController::class, 'edit'] );
            Route::post( 'update/{id}', [ProductPurchaseController::class, 'update'] );
            Route::delete( 'delete/{id}', [ProductPurchaseController::class, 'destroy'] );
            Route::get( 'status/{id}', [ProductPurchaseController::class, 'status'] ); //Product receive
            //Partial payment
            Route::post( 'add-payment/{purchase_id}', [ProductPurchaseController::class, 'addPayment'] );
            Route::get( 'payment-history', [ProductPurchaseController::class, 'paymentHistory'] );
            //Get product supplier wise
            Route::get( '/supplier-product/{supplier_id}', [ProductPurchaseController::class, 'supplierProduct'] );
        } );

        //Purchase Return Route
        Route::prefix( 'tenant-product-purchase/return' )->group( function () {
            Route::get( 'list', [SupplierProductReturnController::class, 'returnList'] );
            Route::get( 'list/{id}', [SupplierProductReturnController::class, 'returnListDetails'] );
            Route::post( '/{id}', [SupplierProductReturnController::class, 'returnToSupplier'] );
        } );

        //Pos Sales Route
        Route::prefix( 'tenant-product-pos-sales' )->group( function () {
            Route::get( '/orders', [ProductPosSaleController::class, 'index'] );
            Route::get( 'create', [ProductPosSaleController::class, 'create'] );
            Route::post( 'store', [ProductPosSaleController::class, 'store'] );
            Route::get( 'show/{id}', [ProductPosSaleController::class, 'show'] );
            Route::get( 'edit/{id}', [ProductPosSaleController::class, 'edit'] );
            Route::post( 'exchange/{id}', [ProductPosSaleController::class, 'exchange'] );
            Route::delete( 'delete/{id}', [ProductPosSaleController::class, 'destroy'] );
            Route::get( 'product/select/{barcode}', [ProductPosSaleController::class, 'productSelect'] ); //Product select
            Route::get( 'scan', [ProductPosSaleController::class, 'scan'] ); //Product select
            //Partial payment
            Route::post( 'add-payment/{sales_id}', [ProductPosSaleController::class, 'addPayment'] );
            Route::get( 'payment-history', [ProductPosSaleController::class, 'paymentHistory'] );
        } );

        //Pos Sales Return Route
        Route::prefix( 'tenant-product-pos-sales-return' )->group( function () {
            Route::get( '/', [PosSaleReturnController::class, 'returnList'] );
            Route::get( 'details/{id}', [PosSaleReturnController::class, 'returnListDetails'] );
            Route::post( '/{id}', [PosSaleReturnController::class, 'returnPosSaleProduct'] );
        } );
        // Product damage
        Route::prefix( 'tenant-product-damage' )->group( function () {
            Route::get( '/', [DamageController::class, 'index'] );
            Route::post( '/store', [DamageController::class, 'store'] );
        } );

        //Pos Sales wastage Return Route
        Route::prefix( 'tenant-product-wastage' )->group( function () {
            Route::post( '/store', [PosSaleReturnController::class, 'returnPosSaleWastageProduct'] );
            Route::get( 'get-invoice', [PosSaleReturnController::class, 'getInvoice'] );
            Route::get( 'show/{id}', [PosSaleReturnController::class, 'returnListDetails'] );
            Route::get( '/', [PosSaleReturnController::class, 'wastageReturnList'] );
        } );

        Route::prefix( 'tenant-coupons' )->group( function () {
            Route::get( 'list', [CouponListController::class, 'index'] );
            Route::post( 'request-send', [CouponRequestController::class, 'store'] );
            Route::get( 'request-list', [CouponRequestController::class, 'getcouponrequest'] );
        } );

        //Report Route
        Route::prefix( 'tenant-report' )->group( function () {
            Route::get( 'stock', [ReportController::class, 'stockReport'] );
            Route::get( 'stock-shortage', [ReportController::class, 'stockShortageReport'] );
            Route::get( 'sales', [ReportController::class, 'salesReport'] );
            Route::get( 'due-sales', [ReportController::class, 'dueSalesReport'] );
            Route::get( 'purchase', [ReportController::class, 'purchaseReport'] );
            Route::get( 'warehouse', [ReportController::class, 'warehouseReport'] );
            Route::get( 'top-repeat-customer', [ReportController::class, 'topRepeatCustomer'] );
            Route::get( 'sales-report-variant', [ReportController::class, 'salesReportVariant'] );
            Route::get( 'sales-report-product-id', [ReportController::class, 'getProductIdsFromSalesDetails'] );
            Route::get( 'sales-report-daily-product-wise', [ReportController::class, 'salesReportDailyProductWise'] );

        } );

        Route::prefix( 'tenant-advertise' )->group( function () {
            Route::get( '/', [AdvertiseController::class, 'index'] );
            Route::get( 'count', [AdvertiseController::class, 'advertiseCount'] );
            Route::get( '/{id}', [AdvertiseController::class, 'show'] );
        } );

        Route::prefix( 'tenant-service' )->group( function () {
            Route::apiResource( '/', ServiceOrderController::class );
        });

        // all sub categories
        Route::get( 'vendor-subcategories', [SubCategoryController::class, 'SubCategoryIndex'] );


        Route::post( 'recharge', [RechargeController::class, 'recharge'] );


        Route::get( 'all-withdraw/history/{status?}', [WithdrawController::class, 'index'] );
        Route::post( 'withdraw-money', [WithdrawController::class, 'withdraw'] );


        Route::get( 'all/banks', [BankController::class, 'index'] );

        //supportbox route
        Route::resource( 'supportbox', SupportBoxController::class );
        Route::post( 'ticket-review', [SupportBoxController::class, 'review'] );
        Route::get( 'support-count', [SupportBoxController::class, 'supportCount'] );
        Route::post( 'ticket-replay', [SupportBoxController::class, 'supportreplay'] );
        Route::get( 'ticket-replay-count', [SupportBoxController::class, 'supportReplyCount'] );



        Route::get( 'all-advertise', [AdvertiseController::class, 'index'] );
        Route::get( 'advertise-count', [AdvertiseController::class, 'advertiseCount'] );
        Route::get( 'advertise/{id}', [AdvertiseController::class, 'show'] );

    } );
} );
