<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ResetPasswordController extends Controller
{
    public function reset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required|integer',
            'password' => 'required|confirmed|min:8',
        ],[
            'token.required' => 'OTP required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400 ,
                'error' => $validator->errors()
            ]);
        }

        // Check if the provided token matches the token stored in the password_resets table
        $tokenData = DB::table('password_resets')
                        ->where('email', $request->email)
                        ->first();

        if($tokenData == null){
            return response()->json([
                'status' => 400 ,
                'message' => 'Invalid OTP'
            ]);
        }

        $expiredTime = Carbon::parse($tokenData->created_at)->addMinutes(5);

        if ($expiredTime->isPast()) {
            return response()->json([
                'status' => 200,
                'message' => 'Verify code is expired!',
            ]);
        }

        if (!$tokenData || !Hash::check($request->token, $tokenData->token)) {
            return response()->json([
                'status' => 400 ,
                'message' => 'Invalid OTP'
            ]);
        }

        // Perform password reset
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->save();
            }
        );

        // Check the status of the password reset operation
        return $status == Password::PASSWORD_RESET
            ? response()->json(['status' => 200 ,'message' => __($status)])
            : response()->json([ 'status' => 400 ,'message' => __($status)]);
    }
}
