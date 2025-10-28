<?php
use App\Http\Controllers\API\Affiliate\BalanceController;
use App\Http\Controllers\API\Affiliate\BankController as AffiliateBankController;
use App\Http\Controllers\API\Affiliate\CartController;
use App\Http\Controllers\API\Affiliate\CheckoutController;
use App\Http\Controllers\API\Affiliate\DashboardController as AffiliateDashboardController;
use App\Http\Controllers\API\Affiliate\OrderController;
use App\Http\Controllers\API\Affiliate\PendingBalanceController;
use App\Http\Controllers\API\Affiliate\ProductRatingController;
use App\Http\Controllers\API\Affiliate\ProductStatusController;
use App\Http\Controllers\API\Affiliate\ProfileController;
use App\Http\Controllers\API\Affiliate\SingleProductController;
use App\Http\Controllers\API\Affiliate\WithdrawController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

//affiliator

Route::middleware( ['auth:sanctum', 'isAPIaffiliator', 'userOnline'] )->group( function () {


    Route::get( 'single/product/{id}', [SingleProductController::class, 'AffiliatorProductSingle'] );
    Route::get( 'single/active/product/{id}', [SingleProductController::class, 'AffiliatoractiveProduct'] );

    Route::post( 'request/product/{id?}', [ProductStatusController::class, 'AffiliatorProductRequest'] );

    Route::get( 'single/page/{id}', [SingleProductController::class, 'AffiliatorProductSinglePage'] );
    Route::post( 'add-to-cart', [CartController::class, 'addtocart'] );
    Route::get( 'cart', [CartController::class, 'viewcart'] );
    Route::put( 'cart-updatequantity/{cart_id}/{scope}', [CartController::class, 'updatequantity'] );
    Route::delete( 'delete-cartitem/{cart_id}', [CartController::class, 'deleteCartitem'] );
    Route::post( 'place-order', [CheckoutController::class, 'placeorder'] );

    Route::post( 'order-create', [OrderController::class, 'store'] );

    Route::get( 'pending-balance', [BalanceController::class, 'PendingBalance'] );
    Route::get( 'active-balance', [BalanceController::class, 'ActiveBalance'] );

    Route::prefix( 'affiliator' )->group( function () {

        Route::get( 'profile', [ProfileController::class, 'AffiliatorProfile'] );
        Route::post( 'update/profile', [ProfileController::class, 'AffiliatorUpdateProfile'] );

        Route::get( 'products', [ProductStatusController::class, 'AffiliatorProducts'] );

        Route::get( 'request/pending/product', [ProductStatusController::class, 'AffiliatorProductPendingProduct'] );
        Route::get( 'request/active/product', [ProductStatusController::class, 'AffiliatorProductActiveProduct'] );
        Route::get( 'vendor-expire-products', [ProductStatusController::class, 'vendorexpireproducts'] );

        Route::get( 'request/reject/product', [ProductStatusController::class, 'AffiliatorProductRejct'] );
        Route::get( 'cat/{id}', [CartController::class, 'affiliatorCart'] );

        Route::get( 'all-orders', [OrderController::class, 'AllOrders'] );
        Route::get( 'pending-orders', [OrderController::class, 'pendingOrders'] );
        Route::get( 'progress-orders', [OrderController::class, 'ProgressOrders'] );
        Route::get( 'received-orders', [OrderController::class, 'receivedOrders'] );
        Route::get( 'delivered-orders', [OrderController::class, 'DeliveredOrders'] );
        Route::get( 'cancel-orders', [OrderController::class, 'CanceldOrders'] );
        Route::get( 'hold-orders', [OrderController::class, 'HoldOrders'] );
        Route::get( 'product-processing', [OrderController::class, 'ProductProcessing'] );
        Route::get( 'order-ready', [OrderController::class, 'OrderReady'] );
        Route::get( 'order-return', [OrderController::class, 'orderReturn'] );

        Route::get( 'order/view/{id}', [OrderController::class, 'orderView'] );

        //pending balance
        Route::get( 'balance/history/{status?}', [PendingBalanceController::class, 'balance'] );

        //bank show
        Route::get( 'banks', [AffiliateBankController::class, 'index'] );

        Route::post( 'withdraw-post', [WithdrawController::class, 'withdraw'] );
        Route::get( 'all-withdraw/{status?}', [WithdrawController::class, 'index'] );

        Route::get( 'dashboard-datas', [AffiliateDashboardController::class, 'index'] );
        Route::get( 'order-vs-comission', [AffiliateDashboardController::class, 'orderVsRevenue'] );
        Route::post( 'product-rating', [ProductRatingController::class, 'rating'] );

        Route::prefix( 'notification' )->group( function () {
            Route::get( '/', [NotificationController::class, 'notification'] );
            Route::get( '/mark-as-read/{id}', [NotificationController::class, 'markAsRead'] );
            Route::get( '/mark-as-read-all', [NotificationController::class, 'markAsReadAll'] );
        } );

        Route::post( 'get-token', [AffiliateDashboardController::class, 'getToken'] );
        Route::post( 'get-cities', [AffiliateDashboardController::class, 'getCities'] );
        Route::post( 'get-zones/{city_id}/{vendor_id}', [AffiliateDashboardController::class, 'getZones'] );
        Route::post( 'get-area/{zone_id}/{vendor_id}', [AffiliateDashboardController::class, 'getArea'] );
        Route::post( 'new-order/{vendor_id}', [AffiliateDashboardController::class, 'newShipmentOrder'] );

    } );

} );
