<?php

declare ( strict_types = 1 );

use App\Http\Controllers\API\ColorController;
use App\Http\Controllers\API\SizeController;
use App\Http\Controllers\API\Vendor\BrandController as VendorBrandController;
use App\Http\Controllers\API\Vendor\CategoryController;
use App\Http\Controllers\API\Vendor\CourierCredentialController;
use App\Http\Controllers\API\Vendor\CustomerController;

// Merchant routes

use App\Http\Controllers\API\Vendor\DeliveryAndPickupAddressController;
use App\Http\Controllers\API\Vendor\DeliveryChargeController;
use App\Http\Controllers\API\Vendor\DeliveryCompanyController;
use App\Http\Controllers\API\Vendor\PaymentMethodController;
use App\Http\Controllers\API\Vendor\ProductManageController;
use App\Http\Controllers\API\Vendor\ProductStatusController;
use App\Http\Controllers\API\Vendor\ProfileController;
use App\Http\Controllers\API\Vendor\SubCategoryController;
use App\Http\Controllers\API\Vendor\SubUnitController;
use App\Http\Controllers\API\Vendor\SupplierController;
use App\Http\Controllers\API\Vendor\UnitController;
use App\Http\Controllers\API\Vendor\VendorController;
use App\Http\Controllers\API\Vendor\WarehouseController;
use App\Http\Controllers\API\Vendor\WoocommerceCredentialController;
use App\Http\Controllers\API\Vendor\WoocommerceOrderController;
use App\Http\Controllers\API\Vendor\WoocommerceProductController;
use App\Http\Controllers\Tenant\TenantAuthController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

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
            Route::get( '/', [ProductManageController::class, 'VendorProduct'] );
            Route::get( 'create', [ProductManageController::class, 'create'] );
            Route::get( 'count', [ProductManageController::class, 'VendorProductCount'] );
            Route::post( 'store', [ProductManageController::class, 'VendorProductStore'] );
            Route::get( 'edit-count', [ProductManageController::class, 'vendorProductEditCount'] );
            Route::get( 'edit/{id}', [ProductManageController::class, 'VendorProductEdit'] );
            Route::post( 'update/{id}', [ProductManageController::class, 'VendorUpdateProduct'] );
            Route::delete( 'delete-image/{id}', [ProductManageController::class, 'VendorDeleteImage'] );
            Route::delete( 'delete/{id}', [ProductManageController::class, 'VendorDelete'] );
        } );

        // Route::get( 'vendor-product-create', [ProductManageController::class, 'create'] );
        // Route::get( 'vendor/product/{status?}', [ProductManageController::class, 'VendorProduct'] );
        // Route::get( 'vendor-product-count/{status?}', [ProductManageController::class, 'VendorProductCount'] );
        // Route::post( 'vendor-store-product', [ProductManageController::class, 'VendorProductStore'] );
        // Route::get( 'vendor-edit-product/{id}', [ProductManageController::class, 'VendorProductEdit'] );
        // Route::get( 'vendor-edit-product-count', [ProductManageController::class, 'vendorProductEditCount'] );
        // Route::post( 'vendor-update-product/{id}', [ProductManageController::class, 'VendorUpdateProduct'] );
        // Route::delete( 'vendor-delete-product/{id}', [ProductManageController::class, 'VendorDelete'] );
        // Route::delete( 'vendor-delete-image/{id}', [ProductManageController::class, 'VendorDeleteImage'] );

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

        Route::prefix( 'payment-method' )->group( function () {
            Route::get( '/', [PaymentMethodController::class, 'index'] );
            Route::post( 'store', [PaymentMethodController::class, 'store'] );
            Route::get( 'edit/{id}', [PaymentMethodController::class, 'edit'] );
            Route::post( 'update/{id}', [PaymentMethodController::class, 'update'] );
            Route::delete( 'delete/{id}', [PaymentMethodController::class, 'destroy'] );
            Route::get( 'status/{id}', [PaymentMethodController::class, 'status'] );
        } );

        // all sub categories
        Route::get( 'vendor-subcategories', [SubCategoryController::class, 'SubCategoryIndex'] );

    } );
} );
