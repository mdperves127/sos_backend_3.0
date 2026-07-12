<?php

namespace App\Services;

use App\Models\ServiceOrder;
use App\Models\ServicePackage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VendorService;
use Illuminate\Support\Facades\DB;

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
            $isTenantContext = ( function_exists( 'tenant' ) && tenant() );
            $price  = (float) ( $package->price ?? 0 );

            // Wallet source:
            // - tenant context: deduct from central mysql.tenants.balance (tenant wallet)
            // - non-tenant context: deduct from central mysql.users.balance (user wallet)
            if ( $isTenantContext ) {
                DB::connection( 'mysql' )->transaction( function () use ( $price ) {
                    $t = Tenant::on( 'mysql' )->lockForUpdate()->find( tenant()->id );
                    if ( !$t ) {
                        return;
                    }
                    $current = (float) convertfloat( (string) ( $t->balance ?? 0 ) );
                    $t->balance = $current - $price;
                    $t->save();
                } );
            } else {
                $userId = userid();
                DB::connection( 'mysql' )->transaction( function () use ( $userId, $price ) {
                    $user = User::on( 'mysql' )->lockForUpdate()->find( $userId );
                    if ( !$user ) {
                        return;
                    }
                    $current = (float) convertfloat( (string) ( $user->balance ?? 0 ) );
                    $user->balance = $current - $price;
                    $user->save();
                } );
            }

            $paymentHistoryContext = [];

            if ( function_exists( 'tenant' ) && tenant() ) {
                $paymentHistoryContext = [
                    'entity_type' => 'tenant',
                    'tenant_id'   => $tenantId,
                    'user_id'     => $serviceOrder->user_id,
                ];
            }

            PaymentHistoryService::store(
                $serviceOrder->trxid,
                $serviceOrder->amount,
                'My wallet',
                'Service',
                '-',
                '',
                $serviceOrder->user_id,
                $paymentHistoryContext
            );

        } else {
            $isTenantContext = function_exists( 'tenant' ) && tenant();
            $successurl      = $isTenantContext
                ? rtrim( request()->getSchemeAndHttpHost(), '/' ) . '/api/aaparpay/service-success'
                : url( 'api/user/aaparpay/service-success' );
            $tenantType      = $isTenantContext ? 'tenant' : 'user';

            return AamarPayService::gateway( $package->price, $trxid, 'Service', $successurl, $tenantType );
        }

        return response()->json([
            'status' => 200,
            'message' => 'Service order created successfully',
            'data' => $serviceOrder,
        ]);
    }
}
