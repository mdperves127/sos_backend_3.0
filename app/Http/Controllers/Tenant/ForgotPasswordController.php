<?php

declare( strict_types = 1 );

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ForgotPasswordController extends Controller
{
    public function sendResetLinkEmail( Request $request ): JsonResponse
    {
        $validator = Validator::make( $request->all(), [
            'email' => ['required', 'email', Rule::exists( 'tenant.users', 'email' )],
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

        if ( ! Schema::connection( 'tenant' )->hasTable( 'password_resets' ) ) {
            Log::error( 'Tenant password_resets table missing', [
                'tenant_id' => function_exists( 'tenant' ) ? tenant( 'id' ) : null,
                'email'     => $user->email,
            ] );

            return response()->json( [
                'status'  => 500,
                'message' => 'Password reset is not available yet. Please contact support.',
            ], 500 );
        }

        $token = (string) random_int( 100000, 999999 );

        DB::connection( 'tenant' )->table( 'password_resets' )->updateOrInsert(
            ['email' => $user->email],
            [
                'email'      => $user->email,
                'token'      => Hash::make( $token ),
                'created_at' => now(),
            ]
        );

        try {
            // Tenant storefront password reset always sends OTP by email.
            Mail::to( $user->email )->send( new ResetPasswordMail( $token ) );
        } catch ( \Throwable $e ) {
            Log::error( 'Tenant forgot password OTP send failed', [
                'tenant_id' => function_exists( 'tenant' ) ? tenant( 'id' ) : null,
                'email'     => $user->email,
                'error'     => $e->getMessage(),
            ] );

            return response()->json( [
                'status'  => 500,
                'message' => 'Failed to send password reset OTP. Please try again later.',
            ], 500 );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'Password reset OTP sent to your email address!',
            'send_to' => $user->email,
        ] );
    }
}
