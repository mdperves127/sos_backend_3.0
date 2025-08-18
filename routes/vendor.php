<?php

use App\Http\Controllers\API\ChatController;
use App\Http\Controllers\API\ColorController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\SizeController;
use App\Http\Controllers\API\SubCategoryController;
use App\Http\Controllers\API\Vendor\AdvancePaymentController;
use App\Http\Controllers\API\Vendor\BankController as VendorBankController;
use App\Http\Controllers\API\Vendor\BarcodeController;
use App\Http\Controllers\API\Vendor\BrandController as VendorBrandController;
use App\Http\Controllers\API\Vendor\CourierCredentialController;
use App\Http\Controllers\API\Vendor\CustomerController;
use App\Http\Controllers\API\Vendor\DamageController;
use App\Http\Controllers\API\Vendor\DashboardController as VendorDashboardController;
use App\Http\Controllers\API\Vendor\DeliveryAndPickupAddressController;
use App\Http\Controllers\API\Vendor\DeliveryChargeController;
use App\Http\Controllers\API\Vendor\DeliveryCompanyController;
use App\Http\Controllers\API\Vendor\OrderController as VendorOrderController;
use App\Http\Controllers\API\Vendor\PaymentMethodController;
use App\Http\Controllers\API\Vendor\PaymentRequestController;
use App\Http\Controllers\API\Vendor\PosSaleReturnController;
use App\Http\Controllers\API\Vendor\ProductManageController;
use App\Http\Controllers\API\Vendor\ProductPosSaleController;
use App\Http\Controllers\API\Vendor\ProductPurchaseController;
use App\Http\Controllers\API\Vendor\ProductStatusController;
use App\Http\Controllers\API\Vendor\ProfileController;
use App\Http\Controllers\API\Vendor\ReportController;
use App\Http\Controllers\API\Vendor\RequestProductController;
use App\Http\Controllers\API\Vendor\SaleOrderResourceController;
use App\Http\Controllers\API\Vendor\SubUnitController;
use App\Http\Controllers\API\Vendor\SupplierController;
use App\Http\Controllers\API\Vendor\SupplierProductReturnController;

//---POS
use App\Http\Controllers\API\Vendor\TestController;
use App\Http\Controllers\API\Vendor\UnitController;
use App\Http\Controllers\API\Vendor\VendorController;
use App\Http\Controllers\API\Vendor\VendorEmployeeController;
use App\Http\Controllers\API\Vendor\VendorInfoController;
use App\Http\Controllers\API\Vendor\WarehouseController;
use App\Http\Controllers\API\Vendor\WoocommerceCredentialController;
use App\Http\Controllers\API\Vendor\WoocommerceOrderController;
use App\Http\Controllers\API\Vendor\WoocommerceProductController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

// vendor
Route::middleware( ['auth:sanctum', 'isAPIVendor'] )->group( function () {

    Route::get( '/checkingAuthenticatedVendor', function () {
        return response()->json( ['message' => 'You are in', 'status' => 200], 200 );
    } );

    Route::get( 'vendor/profile', [ProfileController::class, 'VendorProfile'] );
    Route::post( 'vendor/update/profile', [ProfileController::class, 'VendorUpdateProfile'] );

    //vendor product
    Route::get( 'vendor-product-create', [ProductManageController::class, 'create'] );


    Route::get( 'vendor/product/{status?}', [ProductManageController::class, 'VendorProduct'] );
    Route::get( 'vendor-product-count/{status?}', [ProductManageController::class, 'VendorProductCount'] );
    Route::post( 'vendor-store-product', [ProductManageController::class, 'VendorProductStore'] );
    Route::get( 'vendor-edit-product/{id}', [ProductManageController::class, 'VendorProductEdit'] );
    Route::get( 'vendor-edit-product-count', [ProductManageController::class, 'vendorProductEditCount'] );
    Route::post( 'vendor-update-product/{id}', [ProductManageController::class, 'VendotUpdateProduct'] );
    Route::delete( 'vendor-delete-product/{id}', [ProductManageController::class, 'VendorDelete'] );
    Route::delete( 'vendor-delete-image/{id}', [ProductManageController::class, 'VendorDeleteImage'] );

    Route::get( 'vendor-all-category', [VendorController::class, 'AllCategory'] );
    Route::get( 'vendor-all-subcategory', [VendorController::class, 'AllSubCategory'] );
    // Route::get('vendor-all/brand', [VendorController::class, 'AllBrand']);
    Route::get( 'vendor-all-color', [VendorController::class, 'AllColor'] );
    Route::get( 'vendor-all-size', [VendorController::class, 'AllSize'] );

    //brand create
    Route::post( 'vendor-brand-create', [VendorBrandController::class, 'create'] );
    Route::get( 'vendor-brands', [VendorBrandController::class, 'allBrand'] );
    Route::get( 'vendor-brands/active', [VendorBrandController::class, 'allBrandActive'] );

    Route::delete( 'vendor-brand-delete/{id}', [VendorBrandController::class, 'delete'] );
    Route::get( 'vendor-brand-edit/{id}', [VendorBrandController::class, 'edit'] );
    Route::post( 'vendor-brand-update/{id}', [VendorBrandController::class, 'update'] );

    Route::get( 'vendor/balabrandnce/request', [ProductStatusController::class, 'VendorBalanceRequest'] );
    Route::post( 'vendor/request/sent', [ProductStatusController::class, 'VendorRequestSent'] );

    Route::get( 'vendor-product-approval/{id}', [ProductStatusController::class, 'approval'] );
    Route::get( 'vendor-product-reject/{id}', [ProductStatusController::class, 'reject'] );
    Route::get( 'vendor-all/product-accepted/{id}', [ProductStatusController::class, 'Accepted'] );
    Route::get( 'vendor-product-status-count', [ProductStatusController::class, 'statusCount'] );

    //color
    Route::post( 'store-color', [ColorController::class, 'Colortore'] );
    Route::get( 'view-color/{status?}', [ColorController::class, 'ColorIndex'] );
    Route::get( 'edit-color/{id}', [ColorController::class, 'ColorEdit'] );
    Route::post( 'update-color/{id}', [ColorController::class, 'ColorUpdate'] );
    Route::delete( 'delete-color/{id}', [ColorController::class, 'destroy'] );

    //size route
    Route::post( 'store-size', [SizeController::class, 'Sizestore'] );
    Route::get( 'view-size/{status?}', [SizeController::class, 'SizeIndex'] );
    Route::get( 'edit-size/{id}', [SizeController::class, 'SizeEdit'] );
    Route::put( 'update-size/{id}', [SizeController::class, 'SizeUpdate'] );
    Route::delete( 'delete-size/{id}', [SizeController::class, 'destroy'] );

    //all categories
    Route::get( 'vendor-categories', [ProductController::class, 'AllCategory'] );
    Route::get( 'vendor-category-subcategory/{id}', [ProductController::class, 'catecoryToSubcategory'] );

    // all sub categories
    Route::get( 'vendor-subcategories', [SubCategoryController::class, 'SubCategoryIndex'] );


        //Units Route

        Route::prefix( 'unit' )->group( function () {
            Route::get( '/', [UnitController::class, 'index'] );
            Route::post( 'store', [UnitController::class, 'store'] );
            Route::get( 'edit/{id}', [UnitController::class, 'edit'] );
            Route::post( 'update/{id}', [UnitController::class, 'update'] );
            Route::delete( 'delete/{id}', [UnitController::class, 'destroy'] );
            Route::get( 'status/{id}', [UnitController::class, 'status'] );
        } );

        //Sub-units Route
        Route::prefix( 'sub-unit' )->group( function () {
            Route::get( '/', [SubUnitController::class, 'index'] );
            Route::post( 'store', [SubUnitController::class, 'store'] );
            Route::get( 'edit/{id}', [SubUnitController::class, 'edit'] );
            Route::post( 'update/{id}', [SubUnitController::class, 'update'] );
            Route::delete( 'delete/{id}', [SubUnitController::class, 'destroy'] );
            Route::get( 'status/{id}', [SubUnitController::class, 'status'] );
        } );

        //Ware house Route
        Route::prefix( 'warehouse' )->group( function () {
            Route::get( '/', [WarehouseController::class, 'index'] );
            Route::post( 'store', [WarehouseController::class, 'store'] );
            Route::get( 'edit/{id}', [WarehouseController::class, 'edit'] );
            Route::post( 'update/{id}', [WarehouseController::class, 'update'] );
            Route::delete( 'delete/{id}', [WarehouseController::class, 'destroy'] );
            Route::get( 'status/{id}', [WarehouseController::class, 'status'] );
        } );

        //Suppler Route
        Route::prefix( 'supplier' )->group( function () {
            Route::get( '/', [SupplierController::class, 'index'] );
            Route::post( 'store', [SupplierController::class, 'store'] );
            Route::get( 'edit/{id}', [SupplierController::class, 'edit'] );
            Route::post( 'update/{id}', [SupplierController::class, 'update'] );
            Route::delete( 'delete/{id}', [SupplierController::class, 'destroy'] );
            Route::get( 'status/{id}', [SupplierController::class, 'status'] );
        } );

        //Customer Route
        Route::prefix( 'customer' )->group( function () {
            Route::get( '/', [CustomerController::class, 'index'] );
            Route::post( 'store', [CustomerController::class, 'store'] );
            Route::get( 'edit/{id}', [CustomerController::class, 'edit'] );
            Route::post( 'update/{id}', [CustomerController::class, 'update'] );
            Route::delete( 'delete/{id}', [CustomerController::class, 'destroy'] );
            Route::get( 'status/{id}', [CustomerController::class, 'status'] );
        } );

    // Done

    //affiliator request products
    Route::get( 'affiliator/request-count-{status?}', [RequestProductController::class, 'affiliateRequestCount'] );
    Route::get( 'affiliator/request/product/pending', [RequestProductController::class, 'RequestPending'] );
    Route::get( 'affiliator/request/product/active', [RequestProductController::class, 'RequestActive'] );
    Route::get( 'affiliator/request/product/all', [RequestProductController::class, 'RequestAll'] );
    Route::get( 'affiliator/request/product/rejected', [RequestProductController::class, 'RequestRejected'] );
    Route::get( 'affiliator/request/product/view/{id}', [RequestProductController::class, 'RequestView'] );
    Route::post( 'affiliator/product-update/{id}', [RequestProductController::class, 'RequestUpdate'] );
    Route::get( 'affiliator/membership-expire-product', [RequestProductController::class, 'membershipexpireactiveproduct'] );
    Route::get( 'affiliator/membership-expire-product-count', [RequestProductController::class, 'membershipexpireactiveproductCount'] );

    Route::prefix( 'vendor' )->group( function () {
        //afi orders api
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

        Route::post( 'order/update/{id}', [VendorOrderController::class, 'productorderstatus'] );
        Route::get( 'order/view/{id}', [VendorOrderController::class, 'orderView'] );

        //bank show
        Route::get( 'banks', [VendorBankController::class, 'index'] );

        //vendor payment request
        Route::post( 'payment/submit', [PaymentRequestController::class, 'store'] );
        Route::get( 'payment/history/{status?}', [PaymentRequestController::class, 'history'] );

        Route::get( 'dashboard-datas', [VendorDashboardController::class, 'index'] );
        Route::get( 'order-vs-revenue', [VendorDashboardController::class, 'orderVsRevenue'] );

        Route::get( 'my-note', [VendorDashboardController::class, 'myNote'] );

        // top 10 item
        Route::get( 'vendor/top-ten-items', [VendorDashboardController::class, 'topten'] );

        Route::get( 'product-edit-requests', [ProductManageController::class, 'vendorproducteditrequest'] );
        Route::get( 'product-edit-requests-count', [ProductManageController::class, 'vendorproducteditrequestCount'] );
        Route::get( 'product-edit-requests/{id}', [ProductManageController::class, 'vendorproducteditrequestview'] );
        Route::get( 'transition-history-advance-payment', [AdvancePaymentController::class, 'store'] );

        //---For POS----


        // //Product Route
        // Route::get('product-create', [VendorProductController::class , 'create']);
        // Route::get('product', [VendorProductController::class , 'index']);
        // Route::post('product/store', [VendorProductController::class , 'store']);
        // Route::get('product/edit/{id}', [VendorProductController::class , 'edit']);
        // Route::post('product/update/{id}', [VendorProductController::class , 'update']);
        // Route::delete('product/delete/{id}', [VendorProductController::class , 'destroy']);
        // Route::get('product/status/{id}', [VendorProductController::class , 'status']);

        //Payment Method
        Route::prefix( 'payment-method' )->group( function () {
            Route::get( '/', [PaymentMethodController::class, 'index'] );
            Route::post( 'store', [PaymentMethodController::class, 'store'] );
            Route::get( 'edit/{id}', [PaymentMethodController::class, 'edit'] );
            Route::post( 'update/{id}', [PaymentMethodController::class, 'update'] );
            Route::delete( 'delete/{id}', [PaymentMethodController::class, 'destroy'] );
            Route::get( 'status/{id}', [PaymentMethodController::class, 'status'] );
        } );

        //Sale order resource Route
        Route::prefix( 'pos-order-resource' )->group( function () {
            Route::get( '/', [SaleOrderResourceController::class, 'index'] );
            Route::post( 'store', [SaleOrderResourceController::class, 'store'] );
            Route::get( 'edit/{id}', [SaleOrderResourceController::class, 'edit'] );
            Route::post( 'update/{id}', [SaleOrderResourceController::class, 'update'] );
            Route::delete( 'delete/{id}', [SaleOrderResourceController::class, 'destroy'] );
            Route::get( 'status/{id}', [SaleOrderResourceController::class, 'status'] );
        } );

        //Purchase Route
        Route::prefix( 'product-purchase' )->group( function () {
            Route::get( '/', [ProductPurchaseController::class, 'index'] );
            Route::get( 'create', [ProductPurchaseController::class, 'create'] );
            Route::post( 'store', [ProductPurchaseController::class, 'store'] );
            Route::get( 'show/{id}', [ProductPurchaseController::class, 'show'] );
            Route::get( 'edit/{id}', [ProductPurchaseController::class, 'edit'] );
            Route::post( 'update/{id}', [ProductPurchaseController::class, 'update'] );
            Route::delete( 'delete/{id}', [ProductPurchaseController::class, 'destroy'] );
            Route::get( 'status/{id}', [ProductPurchaseController::class, 'status'] ); //Product receive
            //Partial payment
            Route::post( 'add/payment/{purchase_id}', [ProductPurchaseController::class, 'addPayment'] );
            Route::get( 'supplier/payment/history', [ProductPurchaseController::class, 'paymentHistory'] );
            //Get product supplier wise
            Route::get( '/supplier/product/{supplier_id}', [ProductPurchaseController::class, 'supplierProduct'] );
        } );

        //Purchase Return Route
        Route::prefix( 'product-purchase/return' )->group( function () {
            Route::post( '/{id}', [SupplierProductReturnController::class, 'returnToSupplier'] );
            Route::get( 'list', [SupplierProductReturnController::class, 'returnList'] );
            Route::get( 'list/details/{id}', [SupplierProductReturnController::class, 'returnListDetails'] );
        } );

        //Pos Sales Route
        Route::prefix( 'product-pos-sales' )->group( function () {
            Route::get( '/manage', [ProductPosSaleController::class, 'index'] );
            Route::get( 'create', [ProductPosSaleController::class, 'create'] );
            Route::post( 'store', [ProductPosSaleController::class, 'store'] );
            Route::get( 'show/{id}', [ProductPosSaleController::class, 'show'] );
            Route::get( 'edit/{id}', [ProductPosSaleController::class, 'edit'] );
            Route::post( 'exchange/{id}', [ProductPosSaleController::class, 'exchange'] );
            Route::delete( 'delete/{id}', [ProductPosSaleController::class, 'destroy'] );
            Route::get( 'product/select/{barcode}', [ProductPosSaleController::class, 'productSelect'] ); //Product select
            Route::get( 'scan', [ProductPosSaleController::class, 'scan'] ); //Product select
            //Partial payment
            Route::post( 'add/payment/{sales_id}', [ProductPosSaleController::class, 'addPayment'] );
            Route::get( 'customer/payment/history', [ProductPosSaleController::class, 'paymentHistory'] );
        } );

        //Pos Sales Return Route
        Route::prefix( 'product-pos-sales/return' )->group( function () {
            Route::post( '/{id}', [PosSaleReturnController::class, 'returnPosSaleProduct'] );
            Route::get( '/list', [PosSaleReturnController::class, 'returnList'] );
            Route::get( '/list/details/{id}', [PosSaleReturnController::class, 'returnListDetails'] );
        } );

        //Pos Sales wastage Return Route
        Route::prefix( 'product-pos-sales/wastage/return' )->group( function () {
            Route::get( 'get/invoice', [PosSaleReturnController::class, 'getInvoice'] );
            Route::post( '/', [PosSaleReturnController::class, 'returnPosSaleWastageProduct'] );
            Route::get( '/list', [PosSaleReturnController::class, 'wastageReturnList'] );
            Route::get( '/list/details/{id}', [PosSaleReturnController::class, 'returnListDetails'] );
        } );

        //Manual order Route
        Route::prefix( 'product-manual-order' )->group( function () {
            Route::get( 'create', [VendorOrderController::class, 'create'] );
            Route::get( 'customer/select/{id}', [VendorOrderController::class, 'customerSelect'] );
            Route::post( 'order/store', [VendorOrderController::class, 'orderStore'] );
            Route::get( 'invoice-show/{id}', [VendorOrderController::class, 'invoiceShow'] );
        } );

        //Employee Route
        Route::prefix( 'employee' )->group( function () {
            Route::get( 'manage', [VendorEmployeeController::class, 'index'] );
            Route::get( 'create', [VendorEmployeeController::class, 'create'] );
            Route::post( 'store', [VendorEmployeeController::class, 'store'] );
            Route::get( 'show/{id}', [VendorEmployeeController::class, 'show'] );
            Route::post( 'update/{id}', [VendorEmployeeController::class, 'update'] );
            Route::delete( 'delete/{id}', [VendorEmployeeController::class, 'delete'] );
            Route::get( 'status/{id}', [VendorEmployeeController::class, 'status'] );
            Route::get( 'permissions', [VendorEmployeeController::class, 'permissions'] );
        } );

        //Role
        Route::prefix( 'role-permission' )->group( function () {
            Route::get( 'manage', [VendorEmployeeController::class, 'indexRole'] );
            Route::post( 'store', [VendorEmployeeController::class, 'storeRole'] );
            Route::get( 'show/{id}', [VendorEmployeeController::class, 'showRole'] );
            Route::post( 'update/{id}', [VendorEmployeeController::class, 'updateRole'] );
            Route::delete( 'delete/{id}', [VendorEmployeeController::class, 'deleteRole'] );
        } );

        //Report Route
        Route::prefix( 'report' )->group( function () {
            Route::get( 'stock-report', [ReportController::class, 'stockReport'] );
            Route::get( 'stock-shortage-report', [ReportController::class, 'stockShortageReport'] );
            Route::get( 'sales-report', [ReportController::class, 'salesReport'] );
            Route::get( 'due-sales-report', [ReportController::class, 'dueSalesReport'] );
            Route::get( 'purchase-report', [ReportController::class, 'purchaseReport'] );
            Route::get( 'warehouse-report', [ReportController::class, 'warehouseReport'] );
            Route::get( 'top-repeat-customer', [ReportController::class, 'topRepeatCustomer'] );
            Route::get( 'sales-report-variant', [ReportController::class, 'salesReportVariant'] );
            Route::get( 'sales-report-product-id', [ReportController::class, 'getProductIdsFromSalesDetails'] );
            Route::get( 'sales-report-daily-product-wise', [ReportController::class, 'salesReportDailyProductWise'] );

        } );

        Route::prefix( 'notification' )->group( function () {
            Route::get( '/', [NotificationController::class, 'vendorNotification'] );
            Route::get( '/mark-as-read/{id}', [NotificationController::class, 'markAsRead'] );
            Route::get( '/mark-as-read-all', [NotificationController::class, 'markAsReadAll'] );
        } );

        Route::prefix( 'chat' )->group( function () {
            Route::get( 'message-read', [ChatController::class, 'index'] );
            Route::post( 'message-to', [ChatController::class, 'store'] );
        } );

        Route::prefix( 'barcode' )->group( function () {
            Route::post( 're-generate', [BarcodeController::class, 'reGenerate'] );
            Route::post( 'generate', [BarcodeController::class, 'generate'] );
            Route::get( 'manage', [BarcodeController::class, 'manage'] );
        } );

        Route::prefix( 'info-settings' )->group( function () {
            Route::post( 'update', [VendorInfoController::class, 'update'] );
            Route::get( '/', [VendorInfoController::class, 'show'] );
        } );

        Route::post( 'send-sms', [TestController::class, 'sendSms'] );

        Route::prefix( 'product-damage' )->group( function () {
            Route::get( '/', [DamageController::class, 'index'] );
            Route::post( '/store', [DamageController::class, 'store'] );
        } );

        //Delivery charge Route
        Route::prefix( 'delivery-charge' )->group( function () {
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
        Route::prefix( 'delivery-and-pickup-address' )->group( function () {
            Route::get( '/', [DeliveryAndPickupAddressController::class, 'index'] );
            Route::post( 'store', [DeliveryAndPickupAddressController::class, 'store'] );
            Route::get( 'edit/{id}', [DeliveryAndPickupAddressController::class, 'edit'] );
            Route::post( 'update/{id}', [DeliveryAndPickupAddressController::class, 'update'] );
            Route::delete( 'delete/{id}', [DeliveryAndPickupAddressController::class, 'destroy'] );
            Route::get( 'status/{id}', [DeliveryAndPickupAddressController::class, 'status'] );
        } );

        //courier-credential Route
        Route::prefix( 'courier-credential' )->group( function () {
            Route::get( '/', [CourierCredentialController::class, 'index'] );
            Route::post( 'store', [CourierCredentialController::class, 'store'] );
            Route::get( 'edit/{id}', [CourierCredentialController::class, 'edit'] );
            Route::post( 'update/{id}', [CourierCredentialController::class, 'update'] );
            Route::delete( 'delete/{id}', [CourierCredentialController::class, 'destroy'] );
            Route::get( 'status/{id}', [CourierCredentialController::class, 'status'] );
            Route::get( 'default/{id}', [CourierCredentialController::class, 'default'] );
        } );

        //---For Pathao
        Route::post( 'get-cities/{vendor_id}', [VendorDashboardController::class, 'getCity'] );
        Route::post( 'get-zones/{city_id}/{vendor_id}', [VendorDashboardController::class, 'getZones'] );
        Route::post( 'get-area/{zone_id}/{vendor_id}', [VendorDashboardController::class, 'getArea'] );
        Route::post( 'new-order/{vendor_id}', [VendorDashboardController::class, 'newShipmentOrder'] );

        //----For redx
        Route::get( 'get-redx-area', [VendorDashboardController::class, 'getRedxArea'] );

        //Woocommerce-credential Route
        Route::prefix( 'woocommerce-credential' )->group( function () {
            Route::get( '/', [WoocommerceCredentialController::class, 'index'] );
            Route::post( 'store', [WoocommerceCredentialController::class, 'store'] );
            Route::get( 'edit/{id}', [WoocommerceCredentialController::class, 'edit'] );
            Route::post( 'update/{id}', [WoocommerceCredentialController::class, 'update'] );
            Route::delete( 'delete/{id}', [WoocommerceCredentialController::class, 'destroy'] );
            Route::get( 'status/{id}', [WoocommerceCredentialController::class, 'status'] );
            Route::get( 'default/{id}', [WoocommerceCredentialController::class, 'default'] );
        } );

        //Woocommerce-credential Route
        Route::prefix( 'woocommerce-order' )->group( function () {
            Route::get( '/', [WoocommerceOrderController::class, 'index'] );
            Route::post( 'store', [WoocommerceOrderController::class, 'store'] );
            Route::post( 'delivery/{id}', [WoocommerceOrderController::class, 'wcOrderDelivery'] );
            Route::get( 'order-status/{id}/{status}', [WoocommerceOrderController::class, 'wcOrderStatusUpdate'] );
        } );

        Route::prefix( 'woocommerce-product' )->group( function () {
            Route::get( '/', [WoocommerceProductController::class, 'index'] );
            Route::post( 'store', [WoocommerceProductController::class, 'wcProductStore'] );
        } );

    } );

} );
