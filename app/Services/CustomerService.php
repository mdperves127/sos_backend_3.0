<?php

namespace App\Services;

use App\Models\OrderDelivery;
use App\Models\ServiceOrder;
use App\Models\ServicePackage;
use App\Models\User;

/**
 * Class CustomerService.
 */
class CustomerService
{

    static function service($validateData)
    {
        $serviceOrder = ServiceOrder::find($validateData['service_order_id']);

        if ($validateData['status'] == 'success') {

            // $serviceOrder = ServiceOrder::find($validateData['service_order_id']);
            $serviceOrder->status = 'success';
            $serviceOrder->save();

            // $amount = $serviceOrder->amount;

            if ($serviceOrder->commission_type == 'flat') {
                $amount = $serviceOrder->commission_amount;
            } else {
                $amount =  ($serviceOrder->amount / 100) * $serviceOrder->commission_amount;
            }

            // $admin =  User::where('role_as', 1)->first();
            // $admin->balance = $amount;
            // $admin->save();

            $totalsellerCommition = ($serviceOrder->amount - $amount);

            $vendor = User::find($serviceOrder->vendor_id);
            $vendor->balance = $totalsellerCommition;
            $vendor->save();

            PaymentHistoryService::store(uniqid(), $totalsellerCommition, 'My wallet', 'Service sell', '+', '', $serviceOrder->vendor_id);

        }

        if ($validateData['status'] == 'revision') {

            $servicePackage = ServicePackage::find($serviceOrder->service_package_id);
            $orderDeliveryCount = OrderDelivery::where('service_order_id', $serviceOrder->id)->count();

            if ($servicePackage->revision_max_time < $orderDeliveryCount) {
                return responsejson('Revision limit over!');
            }

            $serviceOrder->status = 'revision';

            $serviceOrder->save();
        }
        return "Successfull!";
    }
}
