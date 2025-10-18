<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    public function AdminProfile()
    {
        // Use request()->user() to avoid Auth facade memory issues
        $user = request()->user();

        // If we only have a minimal user object with ID, fetch the full user
        if (isset($user->id) && !isset($user->name)) {
            $fullUser = User::find($user->id);
            return response()->json([
                'status' => 200,
                'user' => $fullUser
            ]);
        }

        return response()->json([
            'status' => 200,
            'user' => $user
        ]);
    }


    public function AdminUpdateProfile(Request $request)
    {

        $validator =  Validator::make($request->all(), [
            'name' => 'required',
            'number' => 'required',
            'number2'=>'nullable',
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


        $data = User::find(request()->user()->id);
        $data->name = $request->name;
        $data->number = $request->number;
        $data->number2 = $request->number2;
        if ($request->old_password) {
            if (!Hash::check($request->old_password, request()->user()->password)) {
                return response()->json([
                    'status' => 400,
                    'message' => 'Old Password Not Match!'
                ]);
            }else{
                $data->password = bcrypt($request->new_password);
            }
        }


        if($request->hasFile('image'))
        {
            if(File::exists($data->image)){
                File::delete($data->image);
            }
            $image =  fileUpload($request->file('image'),'uploads/admin');
            $data->image = $image;
        }

        $data->save();
        return response()->json([
        'status'=>200,
         'message'=>'Profile Update Sucessfully',
        ]);
    }
}
