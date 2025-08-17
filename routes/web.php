<?php

use App\Enums\Status;
use App\Http\Controllers\API\Admin\DashboardController;
use App\Http\Controllers\TestController;
use App\Models\AdminAdvertise;
use App\Models\CancelOrderBalance;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Coupon;
use App\Models\CouponUsed;
use App\Models\CustomerPayment;
use App\Models\DollerRate;
use App\Models\ExchangeSaleProduct;
use App\Models\Order;
use App\Models\PosSaleReturn;
use App\Models\PosSales;
use App\Models\PosSalesDetails;
use App\Models\PosSaleWastageReturn;
use App\Models\Product;
use App\Models\ProductPurchase;
use App\Models\ProductPurchaseDetails;
use App\Models\ProductVariant;
use App\Models\RolePermission;
use App\Models\ServiceOrder;
use App\Models\Subscription;
use App\Models\SupplierPayment;
use App\Models\SupplierReturnProduct;
use App\Models\SupportBox;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\VendorService;
use App\Models\Withdraw;
use App\Services\SubscriptionDueService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return "API for SOS projec :)";
});

// Auth::routes();


Route::get('migrate', function () {
    Artisan::call('migrate');
    return response()->json('Migration success !');
});

Route::get('rollback', function () {
    Artisan::call('migrate:rollback', [
        '--step' => 1,
    ]);
    return response()->json('Rollback success !');
});

Route::get('seed', function () {
    Artisan::call('db:seed');
});

Route::get('role-permission', function () {

});

// Route::post('send-sms',[TestController::class, 'sendSms']);

Route::get('clear-cache', function () {
    Artisan::call('cache:clear');
    return response()->json('Successfully Clear !');
});

Route::get('clear-pos-system', function(){

    CustomerPayment::truncate();
    ProductPurchase::truncate();
    ProductPurchaseDetails::truncate();
    ProductVariant::truncate();
    SupplierReturnProduct::truncate();
    SupplierPayment::truncate();
    PosSales::truncate();
    PosSalesDetails::truncate();
    PosSaleReturn::truncate();
    PosSaleWastageReturn::truncate();
    ExchangeSaleProduct::truncate();
    Product::query()->update(['qty' => 0]);

    return response()->json('Successfully table Clear !');

});



// Route::get('demo',[DashboardController::class,'index']);
Route::get('demo', function () {

    // return Country::where('id','18')->first()->cites;
    // return User::
    // $test = request('test',null);
    // return User::where('id',1)
    // ->when($test,function(){
    //     dd(1);
    // })
    // ->first();

    return DollerRate::first();

});
