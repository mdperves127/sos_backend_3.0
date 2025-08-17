<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Mail\ResetPasswordMail;
use Illuminate\Support\Facades\Mail;
use App\Services\SmsService;

class ForgotPasswordController extends Controller
{
    // public function sendResetLinkEmail(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'phone_number' => 'required|regex:/^(\+)[0-9]+$/',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json(['error' => $validator->errors()->first()], 400);
    //     }

    //     $user = User::where('phone_number', $request->phone_number)->first();

    //     if (!$user) {
    //         return response()->json(['error' => 'User not found'], 404);
    //     }

    //     $verificationCode = $this->generateVerificationCode();
    //     $user->verification_code = $verificationCode;
    //     $user->save();

    //     $this->sendSMS($request->phone_number, $verificationCode);

    //     return response()->json(['message' => 'Verification code sent to your phone number'], 200);
    // }

    public function sendResetLinkEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 400,
                'message' => $validator->errors(),
            ]);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'status' => 400 ,
                'error' => 'The selected email is invalid']);
        }

        // Generate a unique token for password reset
        $token = rand(100000, 999999);

        // Save the token in the password_resets table
        DB::table('password_resets')->updateOrInsert(
            ['email' => $user->email],
            ['email' => $user->email, 'token' => Hash::make($token), 'created_at' => now()]
        );

        // Send the password reset link via email
        if(otpType() == "sms"){
            $user['verify_code'] = $token;
            SmsService::sendSms($user);
        }else{
            Mail::to($user->email)->send(new ResetPasswordMail($token));
        }



        return response()->json([
            'status' => 200,
            'message' => 'Password reset OTP sent to your' . (otpType() == "sms" ? 'SMS' : 'Email address ') .'!'
        ]);
    }

}
