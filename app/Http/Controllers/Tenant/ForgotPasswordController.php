<?php

declare( strict_types = 1 );

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ForgotPasswordController extends Controller
{
    public function sendResetLinkEmail( Request $request ): JsonResponse
    {
        $validator = Validator::make( $request->all(), [
            'email' => ['required', 'email', Rule::exists('users', 'email')->using('tenant')],
        ], [
            'email.exists' => 'No account found with this email address.',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 400 );
        }

        $user = User::on( 'tenant' )->where( 'email', $request->email )->first();

        if ( ! $user ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'The selected email is invalid',
            ], 400 );
        }

        // Generate a unique 6-digit OTP for password reset
        $token = rand( 100000, 999999 );

        // Save the token in the tenant's password_resets table
        DB::connection( 'tenant' )->table( 'password_resets' )->updateOrInsert(
            [ 'email' => $user->email ],
            [
                'email'      => $user->email,
                'token'      => Hash::make( $token ),
                'created_at' => now(),
            ]
        );

        // Send the password reset OTP via email or SMS
        if ( function_exists( 'otpType' ) && otpType() === 'sms' ) {
            $user['verify_code'] = $token;
            SmsService::sendSms( $user );
        } else {
            Mail::to( $user->email )->send( new ResetPasswordMail( $token ) );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Password reset OTP sent to your ' . ( function_exists( 'otpType' ) && otpType() === 'sms' ? 'SMS' : 'email address' ) . '!',
        ] );
    }
}
