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

class ForgotPasswordController extends Controller
{
    /** @var array<int, string> */
    private const ALLOWED_ROLE_TYPES = ['admin', 'employee', 'tenant_user'];

    public function sendResetLinkEmail( Request $request ): JsonResponse
    {
        $validator = Validator::make( $request->all(), [
            'email'     => ['required', 'email'],
            'role_type' => ['nullable', 'in:admin,employee,tenant_user'],
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 400 );
        }

        if ( ! function_exists( 'tenant' ) || ! tenant() ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Tenant context not found',
            ], 400 );
        }

        $email = strtolower( trim( (string) $request->input( 'email' ) ) );

        // Works for tenant admin, employee, and storefront tenant_user.
        $userQuery = User::on( 'tenant' )
            ->whereRaw( 'LOWER(email) = ?', [$email] )
            ->whereIn( 'role_type', self::ALLOWED_ROLE_TYPES );

        if ( $request->filled( 'role_type' ) ) {
            $userQuery->where( 'role_type', $request->input( 'role_type' ) );
        }

        $user = $userQuery->first();

        if ( ! $user ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'No account found with this email address.',
                'errors'  => [
                    'email' => ['No account found with this email address.'],
                ],
            ], 400 );
        }

        if ( ! Schema::connection( 'tenant' )->hasTable( 'password_resets' ) ) {
            Log::error( 'Tenant password_resets table missing', [
                'tenant_id' => tenant( 'id' ),
                'email'     => $user->email,
                'role_type' => $user->role_type,
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
            Mail::to( $user->email )->send( new ResetPasswordMail( $token ) );
        } catch ( \Throwable $e ) {
            Log::error( 'Tenant forgot password OTP send failed', [
                'tenant_id' => tenant( 'id' ),
                'email'     => $user->email,
                'role_type' => $user->role_type,
                'channel'   => 'email',
                'error'     => $e->getMessage(),
            ] );

            return response()->json( [
                'status'  => 500,
                'message' => 'Failed to send password reset OTP. Please try again later.',
            ], 500 );
        }

        return response()->json( [
            'status'    => 200,
            'message'   => 'Password reset OTP sent to your email address!',
            'send_to'   => $user->email,
            'channel'   => 'email',
            'role_type' => $user->role_type,
        ] );
    }
}
