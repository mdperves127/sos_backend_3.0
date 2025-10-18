<?php

use App\Http\Controllers\Admin\RolePermissionController;
use App\Http\Controllers\Admin\UserRegisterChartController;
use App\Http\Controllers\API\Admin\AdminAdvertiseController;
use App\Http\Controllers\API\Admin\AssignController;
use App\Http\Controllers\API\Admin\BankController;
use App\Http\Controllers\Api\Admin\CampaignCategoryController;
use App\Http\Controllers\API\Admin\CompanionController;
use App\Http\Controllers\API\Admin\ContactController;
use App\Http\Controllers\API\Admin\ContactPageController;
use App\Http\Controllers\Api\Admin\ConversionLocationController;
use App\Http\Controllers\API\Admin\CouponController;
use App\Http\Controllers\API\Admin\DashboardController;
use App\Http\Controllers\API\Admin\DollerPriceController;
use App\Http\Controllers\API\Admin\FaqController;
use App\Http\Controllers\API\Admin\FooterMediaController;
use App\Http\Controllers\API\Admin\ItServiceController;
use App\Http\Controllers\API\Admin\MembersController;
use App\Http\Controllers\API\Admin\MembershipDetailsController;
use App\Http\Controllers\API\Admin\MissionController;
use App\Http\Controllers\API\Admin\NoteController as AdminNoteController;
use App\Http\Controllers\API\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\API\Admin\OrganizationController;
use App\Http\Controllers\API\Admin\OrganizationTwoController;
use App\Http\Controllers\API\Admin\OurServiceController;
use App\Http\Controllers\API\Admin\PartnerController;
use App\Http\Controllers\API\Admin\PaymentHistoryController;
use App\Http\Controllers\Api\Admin\PerformanceGoalController;
use App\Http\Controllers\Api\Admin\PlacementController;
use App\Http\Controllers\API\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ProductStatusController;
use App\Http\Controllers\API\Admin\ProfileController;
use App\Http\Controllers\API\Admin\ServiceOrderShowController;
use App\Http\Controllers\API\Admin\SettingsController;
use App\Http\Controllers\API\Admin\SubscriptionController;
use App\Http\Controllers\API\Admin\SupportBoxCategoryController;
use App\Http\Controllers\API\Admin\SupportBoxController;
use App\Http\Controllers\API\Admin\SupportProblemTopicController;
use App\Http\Controllers\API\Admin\TestimonialController;
use App\Http\Controllers\API\Admin\TicketReplyController;
use App\Http\Controllers\API\Admin\UserEmailSubscribeControllerList;
use App\Http\Controllers\API\Admin\VendorProductController;
use App\Http\Controllers\API\Admin\VendorServiceController;
use App\Http\Controllers\API\Admin\WithdrawController as AdminWithdrawController;
use App\Http\Controllers\API\BrandController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\CouponRequestController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\RechargeController;
use App\Http\Controllers\API\StatisticsController;
use App\Http\Controllers\API\SubCategoryController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\Vendor\ServiceCategoryController;
use App\Http\Controllers\API\Vendor\ServiceSubCategoryController;
use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;


//admin route with full middleware - USING WORKING MIDDLEWARE
Route::middleware( ['adminDatabase', 'adminAuth', 'isAPIAdmin'] )->group( function () {

    // Simple test route
    Route::get( 'admin/test-simple', function () {
        return response()->json(['message' => 'Simple admin test working!']);
    });

    Route::get( 'admin/profile', [ProfileController::class, 'AdminProfile'] );

    Route::post( 'admin/update/profile', [ProfileController::class, 'AdminUpdateProfile'] );


    Route::get( 'admin/request/product/pending', [ProductStatusController::class, 'AdminRequestPending'] );
    Route::get( 'admin/request/product/active', [ProductStatusController::class, 'AdminRequestActive'] );
    Route::get( 'admin/request/product/all', [ProductStatusController::class, 'AdminRequestAll'] );
    Route::get( 'admin/request/product/received', [ProductStatusController::class, 'AdminRequestReceived'] );

    Route::get( 'admin/request/product/rejected', [ProductStatusController::class, 'RequestRejected'] );
    // Route::get('admin/request/product/view/{id}',[AdminController::class,'RequestView']);
    Route::post( 'admin/request/product-update/{id}', [ProductStatusController::class, 'RequestUpdate'] );

    Route::get( 'admin/request/product/view/{id}', [ProductStatusController::class, 'AdminRequestView'] );

    Route::get( 'admin/request/balances', [ProductStatusController::class, 'AdminRequestBalances'] );
    Route::get( 'admin/request/balance/active', [ProductStatusController::class, 'AdminRequestBalanceActive'] );

    Route::get( 'product-approval/{id}', [ProductController::class, 'approval'] );
    Route::get( 'product-reject/{id}', [ProductController::class, 'reject'] );
    Route::get( 'all/product-accepted/{id}', [ProductController::class, 'Accepted'] );

    Route::post( 'store-category', [CategoryController::class, 'CategoryStore'] );
    Route::get( 'view-category', [CategoryController::class, 'CategoryIndex'] );
    Route::get( 'edit-category/{id}', [CategoryController::class, 'CategoryEdit'] );
    Route::post( 'update-category/{id}', [CategoryController::class, 'UpdateCategory'] );
    Route::delete( 'delete-category/{id}', [CategoryController::class, 'destroy'] );

    Route::post( 'category-status/{id}', [CategoryController::class, 'status'] );

    // Route::get('all/category', [CategoryController::class, 'AllCategory']);

    //subcategory route
    Route::post( 'store-subcategory', [SubCategoryController::class, 'SubCategoryStore'] );
    Route::get( 'view-subcategory', [SubCategoryController::class, 'SubCategoryIndex'] );
    Route::get( 'edit-subcategory/{id}', [SubCategoryController::class, 'SubCategoryEdit'] );
    Route::post( 'update-subcategory/{id}', [SubCategoryController::class, 'UpdateSubCategory'] );
    Route::delete( 'delete-subcategory/{id}', [SubCategoryController::class, 'destroy'] );

    Route::post( 'subcategory-status/{id}', [SubCategoryController::class, 'status'] );

    Route::post( 'store-brand', [BrandController::class, 'BrandStore'] );
    Route::get( 'view-brand', [BrandController::class, 'BrandIndex'] );
    Route::get( 'view-brand/active', [BrandController::class, 'BrandActive'] );
    Route::get( 'edit-brand/{id}', [BrandController::class, 'BrandEdit'] );
    Route::post( 'update-brand/{id}', [BrandController::class, 'BrandUpdate'] );
    Route::delete( 'delete-brand/{id}', [BrandController::class, 'destroy'] );

    Route::get( 'all-category', [ProductController::class, 'AllCategory'] );
    Route::get( 'all/brand', [ProductController::class, 'AllBrand'] );

    // Route::post('store-product', [ProductController::class, 'ProductStore']);
    Route::get( 'view-product/{status?}', [ProductController::class, 'ProductIndex'] );
    Route::get( 'edit-product/{id}', [ProductController::class, 'ProductEdit'] );
    Route::put( 'update-product/{id}', [ProductController::class, 'UpdateProduct'] );
    Route::delete( 'delete-product/{id}', [ProductController::class, 'destroy'] );
    Route::post( 'admin-product-status-update/{id}', [ProductController::class, 'updateStatus'] );
    Route::get( 'product-edit-request', [ProductController::class, 'producteditrequest'] );

    Route::get( 'admin/product-rating', [ProductController::class, 'productRating'] );
    Route::delete( 'admin/remove-rating/{id}', [ProductController::class, 'removeRating'] );

    // vendor
    Route::get( 'vendor/view/{name?}', [UserController::class, 'VendorView'] );
    Route::post( 'vendor/store', [UserController::class, 'VendorStore'] );
    Route::get( 'edit-vendor/{id}', [UserController::class, 'VendorEdit'] );
    Route::post( 'update-vendor/{id}', [UserController::class, 'UpdateVendor'] );
    Route::delete( 'delete-vendor/{id}', [UserController::class, 'VendorDelete'] );

    //affiliator
    Route::post( 'affiliator/store', [UserController::class, 'AffiliatorStore'] );
    Route::get( 'affiliator/view/{name?}', [UserController::class, 'AffiliatorView'] );
    Route::get( 'edit-affiliator/{id}', [UserController::class, 'AffiliatorEdit'] );
    Route::post( 'update-affiliator/{id}', [UserController::class, 'UpdateAffiliator'] );
    Route::delete( 'delete-affiliator/{id}', [UserController::class, 'AffiliatorDelete'] );

    // user
    Route::get( 'user/view/{name?}', [UserController::class, 'user'] );
    Route::post( 'user/store', [UserController::class, 'UserStore'] );
    Route::get( 'edit-user/{id}', [UserController::class, 'UserEdit'] );
    Route::post( 'update-user/{id}', [UserController::class, 'Updateuser'] );
    Route::delete( 'delete-user/{id}', [UserController::class, 'UserDelete'] );

    Route::post( 'user/status/update/{id}', [UserController::class, 'updateStatus'] );

    //colors
    // Route::apiResource('admin-colors',AdminColorController::class);

    //size
    // Route::apiResource('admin-size',AdminSizeController::class);

    Route::get( 'admin/all-orders', [AdminOrderController::class, 'allOrders'] );
    Route::get( 'admin/pending-orders', [AdminOrderController::class, 'pendingOrders'] );
    Route::get( 'admin/progress-orders', [AdminOrderController::class, 'ProgressOrders'] );
    Route::get( 'admin/delivered-orders', [AdminOrderController::class, 'DeliveredOrders'] );
    Route::get( 'admin/cancel-orders', [AdminOrderController::class, 'CanceldOrders'] );
    Route::get( 'admin/hold-orders', [AdminOrderController::class, 'HoldOrders'] );
    Route::get( 'admin/received-orders', [AdminOrderController::class, 'ReceivedOrders'] );
    Route::get( 'admin/product-processing', [AdminOrderController::class, 'ProductProcessing'] );
    Route::get( 'admin/order-ready', [AdminOrderController::class, 'OrderReady'] );
    Route::get( 'admin/order-return', [AdminOrderController::class, 'orderReturn'] );

    Route::post( 'admin/order/update/{id}', [AdminOrderController::class, 'updateStatus'] );
    Route::get( 'admin/order/view/{id}', [AdminOrderController::class, 'orderView'] );

    //Delivery company vendor wise
    Route::get( 'admin/deliver-company/{vendor_id}', [AdminOrderController::class, 'getDeliveryCompany'] );

    //bank
    Route::get( 'admin/bank/all', [BankController::class, 'index'] );
    Route::post( 'admin/bank/store', [BankController::class, 'store'] );
    Route::delete( 'admin/bank/delete/{id}', [BankController::class, 'destroy'] );

    //all payment request
    Route::get( 'admin/deposit-history/{status?}', [PaymentHistoryController::class, 'history'] );
    Route::post( 'admin/deposit-history/{id}', [PaymentHistoryController::class, 'statusUpdate'] );

    //all withdraw request
    Route::get( 'admin/all-withdraw/{status?}', [AdminWithdrawController::class, 'index'] );
    Route::post( 'admin/withdraw-paid/{id}', [AdminWithdrawController::class, 'paid'] );
    Route::post( 'admin/withdraw-cancel/{id}', [AdminWithdrawController::class, 'withdrawcancel'] );

    //dashboard data

    Route::get( 'dashboard-datas', [DashboardController::class, 'index'] );
    Route::get( 'admin/order-vs-revenue', [DashboardController::class, 'orderVsRevenue'] );
    Route::get( 'admin/recent-order', [DashboardController::class, 'recentOrder'] );

    Route::get( 'admin/category-status', [DashboardController::class, 'categoryStatus'] );
    Route::get( 'all/view/{status}', [UserController::class, 'alluserlist'] );
    Route::get( 'all/user/{status}', [UserController::class, 'alluserlist'] );

    Route::prefix( 'admin' )->group( function () {
        //service category subcategory
        // Route::resource('servicecategory',ServiceCategoryController::class);

        Route::get( 'subscription-list', [UserController::class, 'subscriptionList'] );
        Route::get( 'coupon-use-list', [CouponController::class, 'couponUseList'] );

        Route::prefix( 'servicecategory' )->group( function () {
            Route::get( '/', [ServiceCategoryController::class, 'index'] );
            Route::post( '/store', [ServiceCategoryController::class, 'store'] );
            Route::post( '/show/{id}', [ServiceCategoryController::class, 'show'] );
            Route::post( '/update/{id}', [ServiceCategoryController::class, 'update'] );
            Route::delete( '/delete/{id}', [ServiceCategoryController::class, 'destroy'] );
            Route::get( '/status/{id}', [ServiceCategoryController::class, 'status'] );
        } );

        Route::resource( 'service-sub-category', ServiceSubCategoryController::class );

        //Notification
        Route::prefix( 'notification' )->group( function () {
            Route::get( '/', [NotificationController::class, 'notification'] );
            Route::get( '/mark-as-read/{id}', [NotificationController::class, 'markAsRead'] );
            Route::get( '/mark-as-read-all', [NotificationController::class, 'markAsReadAll'] );
        } );

        // Home Page
        Route::resource( 'service', OurServiceController::class );
        Route::resource( 'it-service', ItServiceController::class );
        Route::resource( 'organization', OrganizationController::class );
        Route::resource( 'organizationTwo', OrganizationTwoController::class );
        Route::resource( 'partner', PartnerController::class );
        Route::resource( 'companion', CompanionController::class );
        Route::resource( 'member', MembersController::class );
        Route::resource( 'footer-media', FooterMediaController::class );

        // front end settings update
        Route::get( '/settings', [SettingsController::class, 'index'] );
        Route::post( '/settings-update/{id}', [SettingsController::class, 'update'] );

        // User Contact Submitted Infos
        Route::get( '/contact-messages', [ContactController::class, 'index'] );

        Route::get( 'category-status', [DashboardController::class, 'categoryStatus'] );

        Route::resource( 'coupons', CouponController::class );
        Route::get( 'coupon-users', [CouponController::class, 'couponusers'] );

        Route::resource( 'faq', FaqController::class );

        Route::resource( 'mission', MissionController::class );

        Route::resource( 'testimonial', TestimonialController::class );

        Route::resource( 'subscription', SubscriptionController::class );
        Route::post( 'subscription/requirement/{id}', [SubscriptionController::class, 'requirement'] );

        Route::resource( 'supportboxcategory', SupportBoxCategoryController::class );
        Route::resource( 'supportproblem-topic', SupportProblemTopicController::class );
        Route::resource( 'supportbox', SupportBoxController::class );
        Route::resource( 'supportbox-replay', TicketReplyController::class );
        Route::post( 'close-support-box/{id}', [TicketReplyController::class, 'closesupportbox'] );
        Route::post( 'supportbox-status/{id}', [TicketReplyController::class, 'status'] );

        // addvertise section
        Route::resource( 'advertise', AdminAdvertiseController::class );
        Route::post( 'advertise/status', [AdminAdvertiseController::class, 'status'] );
        Route::post( 'advertise/delivery', [AdminAdvertiseController::class, 'delivery'] );
        Route::post( 'advertise/cancel', [AdminAdvertiseController::class, 'cancel'] );

        Route::resource( 'vendor-services', VendorServiceController::class );

        //service order
        Route::resource( 'customer-orders', ServiceOrderShowController::class );

        Route::get( 'doller-price', [DollerPriceController::class, 'index'] );
        Route::post( 'doller-price-store', [DollerPriceController::class, 'store'] );
        Route::get( 'membership-details/affiliate', [MembershipDetailsController::class, 'affiliatemembership'] );
        Route::get( 'membership-details/vendor', [MembershipDetailsController::class, 'vendormembership'] );
        Route::get( 'all-coupon-request', [CouponRequestController::class, 'allcouponrequest'] );
        Route::post( 'coupon-request-status-change/{id}', [CouponRequestController::class, 'changestatus'] );
        Route::post( 'coupon-request-delete/{id}', [CouponRequestController::class, 'deleterequest'] );

        Route::post( 'contact-page', [ContactPageController::class, 'store'] );
        Route::get( 'contact-page-data', [ContactPageController::class, 'index'] );
        Route::get( 'email-subscriber-list', [UserEmailSubscribeControllerList::class, 'index'] );

        Route::get( 'vendor-products-edit-request', [VendorProductController::class, 'index'] );

        Route::get( 'vendor-products-edit-request-view/{id}', [VendorProductController::class, 'productview'] );
        Route::post( 'vendor-products-edit-request-status/{id}', [VendorProductController::class, 'productstatus'] );
        Route::post( 'product-update/{productid}', [AdminProductController::class, 'productupdate'] );
        Route::delete( 'product-image-delete/{id}', [AdminProductController::class, 'deleteproductimage'] );

        Route::resource( 'campaign/category', CampaignCategoryController::class );
        Route::resource( 'conversion/location', ConversionLocationController::class );
        Route::resource( 'performance/goal', PerformanceGoalController::class );
        Route::get( 'get/conversion/location/{id}', [PerformanceGoalController::class, 'getConversionLocation'] );
        Route::get( 'user-register-chart', [UserRegisterChartController::class, 'index'] );

        Route::get( 'dynamic-colum/{colum}', [PlacementController::class, 'index'] );
        Route::post( 'dynamic-colum/{colum}', [PlacementController::class, 'store'] );
        Route::get( 'dynamic-colum/view/{id}/{colum}', [PlacementController::class, 'show'] );
        Route::post( 'dynamic-colum/update/{id}/{colum}', [PlacementController::class, 'update'] );
        Route::delete( 'dynamic-colum/delete/{id}', [PlacementController::class, 'delete'] );

        // Route::get('get-user-permission',[RolePermissionController::class,'permission']);

        Route::get( 'all-permission', [RolePermissionController::class, 'allpermission'] );
        Route::get( 'all-roll', [RolePermissionController::class, 'allroll'] );

        Route::post( 'create-role', [RolePermissionController::class, 'rolepermission'] );
        Route::post( 'update-role/{id}', [RolePermissionController::class, 'rolepermissionupdate'] );
        Route::get( 'role-with-permission/{id}', [RolePermissionController::class, 'rolewithpermission'] );

        Route::post( 'add-manager', [RolePermissionController::class, 'addmanager'] );
        Route::get( 'get-manager-data/{id}', [RolePermissionController::class, 'getaddmanager'] );
        Route::get( 'all-manager-list', [RolePermissionController::class, 'allmanagerlist'] );

        Route::get( 'get-manager-permissions', [RolePermissionController::class, 'managerpermission'] );

        Route::post( 'update-manager-data/{id}', [RolePermissionController::class, 'updateamanager'] );
        Route::post( 'assign-ticket', [AssignController::class, 'store'] );

        // delete role
        Route::delete( 'delete-role/{id}', [RolePermissionController::class, 'deleterole'] );
        Route::delete( 'delete-manager/{id}', [RolePermissionController::class, 'deletemanager'] );

        Route::get( 'user-statistics', [StatisticsController::class, 'users'] );
        Route::get( 'vendor-product-statistics', [StatisticsController::class, 'products'] );
        Route::get( 'affiliate-request-statistics', [StatisticsController::class, 'affiliaterequest'] );
        Route::get( 'manage-productorder-statistics', [StatisticsController::class, 'manageproductorder'] );
        Route::get( 'manage-service-statistics', [StatisticsController::class, 'manageservice'] );
        Route::get( 'service-order-statistics', [StatisticsController::class, 'serviceorder'] );
        Route::get( 'advertise-order-statistics', [StatisticsController::class, 'advertise'] );

        Route::delete( 'remove-rating', [ProductController::class, 'removeRating'] );

        // User balance
        Route::get( 'all-user-balance', [RechargeController::class, 'allUserBalance'] );
        Route::post( 'add-user-balance/{id}', [RechargeController::class, 'addUserBalance'] );
        Route::post( 'edit-user-balance/{id}', [RechargeController::class, 'editUserBalance'] );
        Route::post( 'remove-user-balance/{id}', [RechargeController::class, 'removeUserBalance'] );

        //Note
        Route::get( 'note/index', [AdminNoteController::class, 'index'] );
        Route::post( 'note/store', [AdminNoteController::class, 'store'] );
        Route::get( 'note/vendor/{vendor_id}', [AdminNoteController::class, 'vendorNote'] );
        Route::get( 'vendor/advertise/{vendor_id}', [AdminNoteController::class, 'vendorAdvertise'] );
        Route::get( 'vendor/service/order/{vendor_id}', [AdminNoteController::class, 'vendorServiceOrder'] );
        Route::get( 'vendor/payment/history/{vendor_id}', [AdminNoteController::class, 'vendorPaymentHistory'] );

    } );
}); // End of admin middleware group
