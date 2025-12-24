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
        $vendorService = VendorService::on('mysql')->find($validateData['vendor_service_id']);
        $package = ServicePackage::on('mysql')->find($validateData['service_package_id']);
        $trxid = uniqid();

        // Get tenant_id from vendorService, or fallback to tenant context
        $tenantId = $vendorService->tenant_id;

        $serviceOrder =  ServiceOrder::on('mysql')->create([
            'user_id' => userid() ?? null,
            'vendor_id' => $vendorService->user_id ?? null,
            'vendor_service_id' => $validateData['vendor_service_id'],
            'service_package_id' => $validateData['service_package_id'],
            'amount' => $package->price,
            'commission_amount' =>  $vendorService->commission,
            'commission_type' => $vendorService->commission_type,
            'details'=>request('details'),
            'trxid'=>$trxid,
            'tenant_id'=> $tenantId
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
            $user = User::on('mysql')->find(userid());
            $user->balance = convertfloat($user->balance)- $package->price;
            $user->save();

            PaymentHistoryService::store($serviceOrder->trxid, $serviceOrder->amount, 'My wallet', 'Service', '-', '', $serviceOrder->user_id);

        }else{

            $successurl = url('api/user/aaparpay/service-success');
            return  AamarPayService::gateway($package->price,$trxid,'Service',$successurl, 'user');

        }

        return response()->json([
            'status' => 200,
            'message' => 'Service order created successfully',
            'data' => $serviceOrder,
        ]);
    }
}
