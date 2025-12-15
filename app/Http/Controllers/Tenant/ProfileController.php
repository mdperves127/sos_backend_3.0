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

class ProfileController extends Controller
{
    public function TenantProfile()
    {
        $user = User::where('id', Auth::user()->id)->first();

        return response()->json([
            'status' => 200,
            'user' => $user
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
        return response()->json([
            'status' => 200,
            'shop_info' => $tenant,
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
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'validation_errors' => $validator->messages(),
            ]);
        }


        Tenant::on('mysql')->where('id', tenant()->id)->update([
            'company_name' => $request->company_name,
            'owner_name' => $request->owner_name,
            'phone' => $request->phone,
            'address' => $request->address,
        ]);

        return response()->json([
            "status" => 200,
            "message" => 'Sucessfully update',
        ]);
    }
}
