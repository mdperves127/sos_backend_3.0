<?php

namespace App\Http\Controllers\API\Vendor;

use App\Http\Controllers\Controller;
use App\Models\VendorInfo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class VendorInfoController extends Controller
{
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shop_name' => 'required',
            'phone' => 'required',
            'address' => 'required',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'validation_errors' => $validator->messages(),
            ]);
        }

        $check = VendorInfo::where('vendor_id',vendorId())->exists();

        $info = $check == false ? New VendorInfo() : VendorInfo::where('vendor_id',vendorId())->first();
        $info->vendor_id = vendorId();
        $info->user_id = Auth::id();
        $info->shop_name = $request->shop_name;
        $info->phone = $request->phone;
        $info->email = $request->email;
        $info->address = $request->address;

        $logo = $request->logo;
        if ($logo) {
            $image = uploadany_file($logo, 'uploads/vendor/');
            $info->logo = $image;
        }

        $info->save();

        return response()->json([
            "status" => 200,
            "message" => 'Sucessfully update',
        ]);
    }

    public function show()
    {
        $info = VendorInfo::where('vendor_id',vendorId())->first();
        return response()->json([
            "status" => 200,
            "info" => $info,
        ]);
    }
}
