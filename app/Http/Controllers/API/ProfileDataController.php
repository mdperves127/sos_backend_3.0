<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ProfileDataController extends Controller
{


    public function profile()
    {
        $user = User::find(auth()->id())
        ->load(['usersubscription.subscription:id,card_heading']);

        return response()->json([
            'status' => 200,
            'user' => $user
        ]);
    }
    public function profileupdate(Request $request)
    {

        $validator =  Validator::make($request->all(), [
            'name' => 'required',
            'number' => 'required',
            'number2' => 'nullable',
            'old_password' => 'nullable',
            'new_password' => 'nullable',
            'balance'=>['nullable','numeric','min:1']
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


        $data = User::find(auth()->id());
        $data->name = $request->name;
        $data->number = $request->number;
        $data->number2 = $request->number2;

        if(request('balance') != ''){
            $data->balance =   $request->balance;
        }

        if ($request->old_password) {
            if (!Hash::check($request->old_password, auth()->user()->password)) {
                return response()->json([
                    'status' => 400,
                    'message' => 'Old Password Not Match!'
                ]);
            } else {
                $data->password = bcrypt($request->new_password);
            }
        }


        if ($request->hasFile('image')) {
            if (File::exists($data->image)) {
                File::delete($data->image);
            }
            $image =  fileUpload($request->image, 'uploads/vendor');
            $data->image = $image;
        }

        $data->save();
        return response()->json([
            'status' => 200,
            'message' => 'Profile updated Sucessfully!',
        ]);
    }
}
