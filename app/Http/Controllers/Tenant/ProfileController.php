<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\CmsSetting;
use App\Models\TenantCustomDomain;
use App\Services\CustomDomainService;

class ProfileController extends Controller
{
    public function TenantProfile()
    {
        $user = User::where('id', Auth::user()->id)->first();

        $usersubscription = UserSubscription::on('mysql')
        ->where('tenant_id', tenant()->id)
        ->with('subscription:id,card_heading')->first();

        $cmsSetting = CmsSetting::on('tenant')->first(['theme']);

        return response()->json([
            'status' => 200,
            'user' => $user,
            'usersubscription' => $usersubscription,
            'cms_setting' => $cmsSetting,
        ]);
    }

    public function TenantUpdateProfile(Request $request)
    {
        $validator =  Validator::make($request->all(), [
            'name' => 'required',
            'old_password' => 'nullable',
            'new_password' => 'nullable',
        ]);

        if ($request->has('old_password') && $request->input('old_password') !== null) {
            $validator->addRules([
                'new_password' => 'required|min:8|max:32',
            ]);
        }

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'message' => $validator->messages()
            ]);
        }


        $data = User::find(Auth::user()->id);
        $data->name = $request->name;

        if($request->has('new_password') && $request->has('old_password')) {
            if($request->new_password == $request->old_password) {
                return response()->json([
                    'status' => 400,
                    'message' => 'New Password and Old Password cannot be the same!'
                ]);
            }
        }

        if ($request->has('old_password')) {
            if (!Hash::check($request->old_password, $data->password)) {
                return response()->json([
                    'status' => 400,
                    'message' => 'Old Password Not Match!'
                ]);
            } else {
                $data->password = bcrypt($request->new_password);
            }
        }


        // if ($request->hasFile('image')) {
        //     if (File::exists($data->image)) {
        //         File::delete($data->image);
        //     }
        //     $image =  fileUpload($request->image, 'uploads/vendor');
        //     $data->image = $image;
        // }

        $data->save();
        return response()->json([
            'status' => 200,
            'message' => 'Profile updated Sucessfully!',
            'user' => $data,
            'tenant_type' => Tenant::on('mysql')->where('id', tenant()->id)->first()->type,
        ]);
    }

    public function shopInfo()
    {
        $tenant = Tenant::on('mysql')->where('id', tenant()->id)->first();
        $domainRecord = TenantCustomDomain::on('mysql')->where('tenant_id', tenant()->id)->first();
        $targetIp = app(CustomDomainService::class)->targetIp();

        return response()->json([
            'status' => 200,
            'shop_info' => $tenant,
            'custom_domain_connection' => $domainRecord ? [
                'domain' => $domainRecord->domain,
                'status' => $domainRecord->status,
                'verification' => $domainRecord->verification,
                'ssl' => $domainRecord->ssl,
                'target_ip' => $domainRecord->target_ip,
                'verified_at' => $domainRecord->verified_at,
                'activated_at' => $domainRecord->activated_at,
                'last_dns_check' => $domainRecord->last_dns_check,
            ] : null,
            'dns_instructions' => [
                'type' => 'A',
                'host' => '@',
                'value' => $targetIp,
            ],
        ]);
    }

    public function shopInfoUpdate(Request $request)
    {

        $tenant = Tenant::on('mysql')->where('id', tenant()->id)->first();


        $validator = Validator::make($request->all(), [
            'company_name' => 'required',
            'owner_name' => 'required',
            'phone' => 'required|unique:mysql.tenants,phone,' . $tenant->id,
            'address' => 'required',
            'custom_domain' => 'nullable|string|max:255|unique:mysql.tenants,custom_domain,' . $tenant->id,
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'validation_errors' => $validator->messages(),
            ]);
        }


        $updateData = [
            'company_name' => $request->company_name,
            'owner_name'   => $request->owner_name,
            'phone'        => $request->phone,
            'address'      => $request->address,
        ];

        if ( $request->filled( 'custom_domain' ) ) {
            $updateData['custom_domain'] = $request->custom_domain;
        }

        Tenant::on('mysql')->where('id', tenant()->id)->update( $updateData );

        return response()->json([
            "status" => 200,
            "message" => 'Sucessfully update',
        ]);
    }
}
