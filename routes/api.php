<?php

// Test route to verify API routes are working
Route::get( '/test-api', function () {
    return response()->json( [
        'message'   => 'API routes are working!',
        'timestamp' => now(),
    ] );
} );

use App\Http\Controllers\AamarpayController;
use App\Http\Controllers\AdvertiseController;
use App\Http\Controllers\API\Admin\AdminAdvertiseController;
use App\Http\Controllers\API\Admin\SupportBoxCategoryController;
use App\Http\Controllers\API\Affiliate\BankController;
use App\Http\Controllers\API\Affiliate\WithdrawController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ConversationController;
use App\Http\Controllers\API\CouponListController;
use App\Http\Controllers\API\CouponRequestController;
use App\Http\Controllers\API\ForgotPasswordController;
use App\Http\Controllers\API\MessageController;
use App\Http\Controllers\API\ProfileDataController;
use App\Http\Controllers\API\RechargeController;
use App\Http\Controllers\API\ResetPasswordController;
use App\Http\Controllers\API\ServiceBuyStatusController;
use App\Http\Controllers\API\ServiceOrderController;
use App\Http\Controllers\API\ServiceRatingController;
use App\Http\Controllers\API\SubscriptionAlertController;
use App\Http\Controllers\API\SupportBoxCloseController;
use App\Http\Controllers\API\SupportBoxController;
use App\Http\Controllers\API\User\ContactController;
use App\Http\Controllers\API\User\ContactPageController;
use App\Http\Controllers\API\User\EmailSubscribeController;
use App\Http\Controllers\API\User\SettingsController;
use App\Http\Controllers\API\Vendor\OrderDeliveryController;
use App\Http\Controllers\API\Vendor\ServiceCategoryController;
use App\Http\Controllers\API\Vendor\VendorServiceController;
use App\Http\Controllers\BuySubscription;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\CpanelController;
use App\Http\Controllers\DollerRateController;
use App\Http\Controllers\RenewController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TenantRegistrationController;
use Illuminate\Support\Facades\Route;

//register
Route::post( 'register', [AuthController::class, 'Register'] );
Route::post( 'verify', [AuthController::class, 'verify'] );
Route::post( 'resend/verify/code', [AuthController::class, 'resendVerifyCode'] );
// Route::get( 'transition-history', [HistoryController::class, 'index'] );

//login
Route::post( 'login', [AuthController::class, 'login'] );
Route::post( 'logout', [AuthController::class, 'logout'] );

Route::post( 'forgot/password', [ForgotPasswordController::class, 'sendResetLinkEmail'] );
Route::post( 'password/reset', [ResetPasswordController::class, 'reset'] );
Route::middleware( [
    'adminDatabase',
    // 'auth:sanctum',
    'isUser',
] )->group( function () {

    Route::resource( 'main-services', VendorServiceController::class );
    Route::get( 'main-service-count', [VendorServiceController::class, 'serviceCount'] );
    Route::get( 'service/orders', [VendorServiceController::class, 'serviceorders'] );
    Route::get( 'service/order-count', [VendorServiceController::class, 'serviceOrderCount'] );
    Route::post( 'service/status', [VendorServiceController::class, 'statusChange'] );

    Route::get( 'service/myorders/{id}', [VendorServiceController::class, 'singlemyorder'] );

    Route::get( 'service/orders/view/{id}', [VendorServiceController::class, 'ordersview'] );

    Route::get( 'service-category-subcategory', [VendorServiceController::class, 'categorysubcategory'] );
    Route::resource( 'service/delivery-to-customer', OrderDeliveryController::class );

    Route::resource( 'user/supportbox', SupportBoxController::class );
    Route::post( 'user/ticket-review', [SupportBoxController::class, 'review'] );
    Route::get( 'user/support-count', [SupportBoxController::class, 'supportCount'] );

    Route::post( 'user/ticket-replay', [SupportBoxController::class, 'supportreplay'] );
    Route::get( 'user/ticket-replay-count', [SupportBoxController::class, 'supportReplyCount'] );

    Route::apiResource( 'service/order', ServiceOrderController::class );
    Route::get( 'service-buy-count', [ServiceOrderController::class, 'serviceOrderCount'] );
    Route::post( 'service/order/status', [ServiceOrderController::class, 'status'] );
    // Route::apiResource('coupon-list', CouponUsedController::class);
    Route::get( 'user/all-ticket-category', [SupportBoxCategoryController::class, 'index'] );
    Route::get( 'user/ticket-category-to-problem/{id}', [SupportBoxCategoryController::class, 'ticketcategorytoproblem'] );

    Route::get( 'buy/subscription/{id}', [BuySubscription::class, 'buy'] );
    Route::post( 'apply/coupon', [BuySubscription::class, 'coupon'] );
    Route::post( 'buy-subscription', [BuySubscription::class, 'buysubscription'] );

    Route::post( 'create-advertise', [AdminAdvertiseController::class, 'store'] );
    // advertise-success

    Route::get( 'user/all-advertise', [AdvertiseController::class, 'index'] );
    Route::get( 'user/advertise-count', [AdvertiseController::class, 'advertiseCount'] );
    Route::get( 'user/advertise/{id}', [AdvertiseController::class, 'show'] );

    Route::get( 'coupon-lists', [CouponListController::class, 'index'] );
    Route::post( 'renew-subscription', [RenewController::class, 'store'] );

    Route::post( 'user/recharge', [RechargeController::class, 'recharge'] );

    Route::post( 'service-rating', [ServiceRatingController::class, 'store'] );
    Route::post( 'user/withdraw-money', [WithdrawController::class, 'withdraw'] );
    Route::get( 'all-withdraw/history/{status?}', [WithdrawController::class, 'index'] );

    Route::post( 'coupon-request-send', [CouponRequestController::class, 'store'] );
    Route::get( 'get-coupon-request', [CouponRequestController::class, 'getcouponrequest'] );

    Route::get( 'user/all/banks', [BankController::class, 'index'] );

    Route::post( 'user/supportbox-close/{id}', [SupportBoxCloseController::class, 'index'] );

    Route::get( 'profile-data', [ProfileDataController::class, 'profile'] );
    Route::post( 'profile-data-update', [ProfileDataController::class, 'profileupdate'] );
    Route::get( 'subscription-notification', [SubscriptionAlertController::class, 'index'] );
    Route::post( 'service-buy-status', [ServiceBuyStatusController::class, 'index'] );
    Route::post( 'cancel-own-serviceorder-request', [ServiceBuyStatusController::class, 'cancelownserviceorderrequest'] );
    Route::post( 'cancel-other-serviceorder-request', [ServiceBuyStatusController::class, 'cancelotherserviceorderrequest'] );

    //Conversation
    Route::get( 'conversation', [ConversationController::class, 'index'] );
    Route::post( '/messages/send', [MessageController::class, 'sendMessage'] );
    Route::get( '/messages/{vendorId}', [MessageController::class, 'getMessages'] );

    Route::post( 'chat-report/{id}', [MessageController::class, 'chatReport'] );

    Route::get( 'get-category', [SettingsController::class, 'getCategory'] );

} );

Route::prefix( 'aaparpay' )->group( function () {

    Route::post( 'advertise-success', [AamarpayController::class, 'advertisesuccess'] );
    Route::post( 'service-success', [AamarpayController::class, 'servicesuccess'] );
    Route::post( 'renew-success', [AamarpayController::class, 'renewsuccess'] );
    Route::post( 'recharge-success', [AamarpayController::class, 'rechargesuccess'] );

    Route::post( 'subscription-success', [AamarpayController::class, 'subscriptionsuccess'] );
    Route::post( 'product-checkout-success', [AamarpayController::class, 'productcheckoutsuccess'] );

    Route::post( 'fail', [AamarpayController::class, 'fail'] );
    Route::get( 'cancel', [AamarpayController::class, 'cancel'] );
} );

Route::post( '/contact-store', [ContactController::class, 'store'] );
Route::get( 'all-services', [VendorServiceController::class, 'allservice'] );
Route::get( 'services-view/{id}', [VendorServiceController::class, 'serviceshow'] );
Route::get( 'services-rating/{id}', [VendorServiceController::class, 'servicerating'] );

Route::get( 'doller-rate', [DollerRateController::class, 'index'] );
Route::get( '/settings', [SettingsController::class, 'index'] );

Route::get( '/companions', [SettingsController::class, 'companion'] );

Route::get( '/faqs', [SettingsController::class, 'faq'] );

Route::get( '/footer-medias', [SettingsController::class, 'fottermedia'] );

Route::get( '/members', [SettingsController::class, 'members'] );

Route::get( '/missions', [SettingsController::class, 'mission'] );

Route::get( '/org-one', [SettingsController::class, 'orgOne'] );

Route::get( '/org-two', [SettingsController::class, 'orgTwo'] );

Route::get( '/services', [SettingsController::class, 'service'] );

Route::get( '/it-services', [SettingsController::class, 'Itservice'] );

Route::get( '/partners', [SettingsController::class, 'partner'] );

Route::get( '/testimonials', [SettingsController::class, 'testimonial'] );

Route::get( 'front-campaign-category', [SettingsController::class, 'campaignCategory'] );
Route::get( 'front-campaign-converstion-location/{id}', [SettingsController::class, 'campaignConverstionLocation'] );
Route::get( 'front-campaign-performance-goal/{id}', [SettingsController::class, 'campaignPerformanceGoal'] );
Route::get( 'front-dynamic-data/{colum}/{id?}', [SettingsController::class, 'campaignDynamicData'] );

Route::get( '/subscriptions', [SubscriptionController::class, 'index'] );

Route::get( 'contact-page-data', [ContactPageController::class, 'index'] );
Route::post( 'email-subscribe', [EmailSubscribeController::class, 'store'] );

Route::get( 'service-category', [ServiceCategoryController::class, 'index'] );

Route::get( 'countries', [CountryController::class, 'country'] );
Route::get( 'cities/{id}', [CountryController::class, 'cities'] );

Route::middleware( 'auth:sanctum' )->get( '/user', function () {
    return isactivemembership();
} );

// Tenant Registration API Routes (these don't need tenancy context as they manage tenants from central)
Route::post( '/tenants/register', [TenantRegistrationController::class, 'register'] );

// cPanel API Routes (environment-based subdomain and database creation)
Route::prefix( 'cpanel' )->group( function () {
    Route::post( '/subdomain', [CpanelController::class, 'createSubdomain'] );
    Route::post( '/database', [CpanelController::class, 'createDatabase'] );
    Route::post( '/infrastructure', [CpanelController::class, 'createTenantInfrastructure'] );
    Route::get( '/environment-info', [CpanelController::class, 'getEnvironmentInfo'] );
} );
