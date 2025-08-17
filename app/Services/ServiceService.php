<?php

namespace App\Services;

use App\Models\ServiceOrder;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\VendorService;

/**
 * Class ServiceService.
 */
class ServiceService
{
    static function store($validateData){
        $vendorService = VendorService::find($validateData['vendor_service_id']);
        $package = ServicePackage::find($validateData['service_package_id']);
        $trxid = uniqid();

        $serviceOrder =  ServiceOrder::create([
            'user_id' => userid(),
            'vendor_id' => $vendorService->user_id,
            'vendor_service_id' => $validateData['vendor_service_id'],
            'service_package_id' => $validateData['service_package_id'],
            'amount' => $package->price,
            'commission_amount' =>  $vendorService->commission,
            'commission_type' => $vendorService->commission_type,
            'details'=>request('details'),
            'trxid'=>$trxid
        ]);

        if(request()->hasFile('files')){
            foreach(request('files') as $file){
                $name = uploadany_file($file);
                $serviceOrder->files()->create([
                    'name'=>$name
                ]);
            }
        }

        if(request('payment_type') == "my-wallet"){
            $serviceOrder->update([
                'is_paid'=>1
            ]);
            $user = User::find(userid());
            $user->balance = convertfloat($user->balance)- $package->price;
            $user->save();

            PaymentHistoryService::store($serviceOrder->trxid, $serviceOrder->amount, 'My wallet', 'Service', '-', '', $serviceOrder->user_id);

        }else{

            $successurl = url('api/aaparpay/service-success');
            return  AamarPayService::gateway($package->price,$trxid,'Service',$successurl);

        }

        return "Success";
    }

}
