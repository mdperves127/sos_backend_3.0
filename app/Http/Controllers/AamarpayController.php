<?php

namespace App\Http\Controllers;

use App\Models\AdminAdvertise;
use App\Models\PaymentStore;
use App\Models\ServiceOrder;
use App\Models\VendorService;
use App\Models\CustomerRequiremnt;
use App\Models\DollerRate;
use App\Models\ServicePackage;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PaymentHistoryService;
use App\Services\ProductCheckoutService;
use App\Services\SubscriptionRenewService;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Notification;
use App\Notifications\RechargeNotification;
use App\Notifications\SubscriptionNotification;
use App\Models\Tenant;

class AamarpayController extends Controller
{

    function servicesuccess()
    {
        $response = request()->all();
        $vendorservice = ServiceOrder::where('trxid', $response['mer_txnid'])->first();
        $vendorservice->update([
            'is_paid' => 1
        ]);
        PaymentHistoryService::store($vendorservice->trxid, $vendorservice->amount, 'Ammarpay', 'Service', '-', '', $vendorservice->user_id);

        $user = User::find($vendorservice->user_id);
        $path = paymentredirect($user->role_as);
        $url = config('app.redirecturl') . $path . '?message=Service purchase successfully';
        return redirect($url);


    }

    function productcheckoutsuccess()
    {

        $response = request()->all();
        $data = PaymentStore::where('trxid', $response['mer_txnid'])->first();

        if (!$data) {
            return false;
        }
        $info = $data->info;

        // PaymentHistoryService::store($data->trxid, $response['amount'], 'Ammarpay', 'Payment Checkout', '-', '', $info['userid']);
        ProductCheckoutService::store($info['cartid'], $info['productid'], $info['totalqty'], $info['userid'], $info['datas']);

        $user = User::find($info['userid']);
        $path = paymentredirect($user->role_as);
        $url = config('app.redirecturl') . $path . '?message=Product purchase successfully';
        return redirect($url);

    }

    function renewsuccess()
    {
        $response = request()->all();
        $data = PaymentStore::where(['trxid' => $response['mer_txnid'], 'status' => 'pending'])->first();
        $user =  User::find($data['info']['user_id']);
        $subscriptionid =  $data['info']['package_id'];
        $trxid = $data->trxid;
        $payment_method = 'Aamarpay';
        $transition_type = 'renew';
        SubscriptionRenewService::subscriptionadd($user, $subscriptionid, $trxid, $payment_method, $transition_type, $totalsubscriptionamount = $response['amount_original'], $couponName = $data['info']['coupon']);


        $path = paymentredirect($user->role_as);
        $url = config('app.redirecturl') . $path . '?message=Renew successfull';
        return redirect($url);
    }
    function advertisesuccess()
    {
        $response = request()->all();
        $adminAdvertise = AdminAdvertise::on('mysql')->where('trxid', $response['mer_txnid'])->first();

        $adminAdvertise->update([
            'is_paid' => 1
        ]);
        $dollerRate  =  DollerRate::on('mysql')->first()?->amount;
        // if($response['opt_b'] == 'user'){
            PaymentHistoryService::on('mysql')->store($adminAdvertise->trxid, ($adminAdvertise->budget_amount * $dollerRate), 'Ammarpay', 'Advertise', '-', '', $adminAdvertise->user_id);
        // }else{
        //     PaymentHistoryService::store($adminAdvertise->trxid, ($adminAdvertise->budget_amount * $dollerRate), 'Ammarpay', 'Advertise', '-', '', tenant()->id);
        // }
        $user = User::on('mysql')->find($adminAdvertise->user_id);
        $path = paymentredirect($user->role_as);
        $url = config('app.redirecturl') . $path . '?message=Advertise payment successfull';
        return redirect($url);
    }

    function subscriptionsuccess()
    {
        $response = request()->all();

        $data = PaymentStore::where(['trxid' => $response['mer_txnid'], 'status' => 'pending'])->first();
        $validatedData  =  $data['info'];
        $subscription = Subscription::find($validatedData['subscription_id']);
        $user = User::find($validatedData['user_id']);
        $couponid = $validatedData['coupon_id'];

        if (!$data) {
            return false;
        }

        if ($response['opt_a'] == 'subscription') {
            $amount = $response['amount_original'];
           $subscriptiondata =  SubscriptionService::store($subscription, $user, $amount, $couponid, 'Aamarpay');
        }

        if(!is_object($subscriptiondata)){
            if($subscriptiondata == '2' || $subscriptiondata == 3){
                $tokens = $user->tokens;

                foreach ($tokens as $token) {
                    $token->delete();
                }
                return redirect(config('app.maindomain'));
            }
        }


        $path = paymentredirect($user->role_as);
        $url = config('app.redirecturl') . $path . '?message=Subscription added successfull';

        //For user
        $subscriptionText = "Congratulations! Your package was successfully purchased!";
        Notification::send($user, new SubscriptionNotification($user , $subscriptionText));

        //For admin
        $normalUser = User::find($validatedData['user_id']); // Vendor or affiliate
        $user = User::where('role_as',1)->first(); //Admin
        $subscriptionText = $normalUser->email ."Purchase a new package";
        Notification::send($user, new SubscriptionNotification($user, $subscriptionText));

        return redirect($url);
    }

    function rechargesuccess()
    {
        $response = request()->all();
        $data = PaymentStore::on('mysql')->where(['trxid' => $response['mer_txnid']])->first();

        if($response['opt_b'] == 'user'){
            PaymentHistoryService::store($data->trxid, $data['info']['amount'],  'Ammarpay', 'Recharge', '+', '',  $data['info']['user_id']);
        }else{
            PaymentHistoryService::store($data->trxid, $data['info']['amount'],  'Ammarpay', 'Recharge', '+', '',  $data['info']['user_id']);
        }

        // User::find($data['info']['user_id'])->increment('balance', $data['info']['amount']);

        $tenant = null;
        if($response['opt_b'] == 'tenant'){
            $tenant = Tenant::find(tenant()->id);
            $tenant->increment('balance', $data['info']['amount']);
        }else{
            $tenant = User::find($data['info']['user_id']);
            $tenant->increment('balance', $data['info']['amount']);
        }

        // $user = Tenant::find(tenant()->id);
        // dd($user);
        // $path = paymentredirect($user->role_as);
        // $url = config('app.redirecturl') . $path . '?message=Recharge successful';
        // $url = config('app.redirecturl') . 'tenant/dashboard?message=Recharge successful';

        if ($tenant) {
            Notification::send($tenant, new RechargeNotification($tenant, $data['info']['amount'] , $data->trxid));
        }

        // Redirect based on user type
        if($response['opt_b'] == 'tenant'){
            $url = config('app.redirecturl') . 'tenant/dashboard?message=Recharge successful';
        } else {
            $user = User::find($data['info']['user_id']);
            $path = paymentredirect($user->role_as);
            $url = config('app.redirecturl') . $path . '?message=Recharge successful';
        }

        return redirect($url);
    }



    function fail()
    {
        return redirect(config('app.maindomain'));
    }

    function cancel()
    {
        return redirect(config('app.maindomain'));
    }
}
