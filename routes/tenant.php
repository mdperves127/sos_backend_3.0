<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use App\Http\Controllers\Tenant\TenantRegistrationController;
use App\Http\Controllers\Tenant\TenantAuthController;
use Illuminate\Http\Request;
use App\Http\Controllers\API\Vendor\ProductManageController;




// Merchant routes

use App\Http\Controllers\API\Vendor\ProfileController;
use App\Http\Controllers\API\Vendor\VendorController;
use App\Http\Controllers\API\Vendor\BrandController as VendorBrandController;
use App\Http\Controllers\API\Vendor\CategoryController;
use App\Http\Controllers\API\Vendor\ProductStatusController;
use App\Http\Controllers\API\ColorController;
use App\Http\Controllers\API\SizeController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\SubCategoryController;




Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/', function () {
        return response()->json([
            'message' => 'Tenant subdomain is working!',
            'tenant_id' => tenant('id'),
            'domain' => request()->getHost(),
            'timestamp' => now()
        ]);
    });
    // Public tenant routes
    Route::post('/auth/login', [TenantAuthController::class, 'login']);
    Route::post('/auth/register', [TenantAuthController::class, 'register']);

    // Protected tenant routes
    Route::middleware('tenantAuth')->group(function () {
        Route::post('/auth/logout', [TenantAuthController::class, 'logout']);
        Route::put('/auth/profile', [TenantAuthController::class, 'updateProfile']);
        Route::get('/auth/profile/info', [TenantAuthController::class, 'profileInfo']);
        Route::put('/auth/change-password', [TenantAuthController::class, 'changePassword']);




        //Vendor Routes
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
        // Route::get( 'vendor-categories', [ProductController::class, 'AllCategory'] );
        // Route::get( 'vendor-category-subcategory/{id}', [ProductController::class, 'catecoryToSubcategory'] );


        Route::get('all-category', [CategoryController::class, 'index']);

        // all sub categories
        Route::get( 'vendor-subcategories', [SubCategoryController::class, 'SubCategoryIndex'] );

    });
});

