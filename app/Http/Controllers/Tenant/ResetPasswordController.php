<?php

declare( strict_types = 1 );

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class ResetPasswordController extends Controller
{
    /** @var array<int, string> */
    private const ALLOWED_ROLE_TYPES = ['admin', 'employee', 'tenant_user'];

    public function reset( Request $request ): JsonResponse
    {
        $validator = Validator::make( $request->all(), [
            'email'     => 'required|email',
            'token'     => 'required',
            'password'  => 'required|confirmed|min:8',
            'role_type' => ['nullable', 'in:admin,employee,tenant_user'],
        ], [
            'token.required' => 'OTP is required',
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

        $tokenData = DB::connection( 'tenant' )
            ->table( 'password_resets' )
            ->whereRaw( 'LOWER(email) = ?', [$email] )
            ->first();

        if ( $tokenData === null ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Invalid OTP',
            ], 400 );
        }

        $expiredTime = Carbon::parse( $tokenData->created_at )->addMinutes( 15 );

        if ( $expiredTime->isPast() ) {
            DB::connection( 'tenant' )
                ->table( 'password_resets' )
                ->whereRaw( 'LOWER(email) = ?', [$email] )
                ->delete();

            return response()->json( [
                'status'  => 400,
                'message' => 'OTP has expired. Please request a new one.',
            ], 400 );
        }

        if ( ! Hash::check( (string) $request->token, $tokenData->token ) ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'Invalid OTP',
            ], 400 );
        }

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
                'message' => 'User not found',
            ], 400 );
        }

        $user->forceFill( [
            'password' => Hash::make( $request->password ),
        ] )->save();

        DB::connection( 'tenant' )
            ->table( 'password_resets' )
            ->whereRaw( 'LOWER(email) = ?', [$email] )
            ->delete();

        return response()->json( [
            'status'    => 200,
            'message'   => 'Password reset successfully. You can now login with your new password.',
            'role_type' => $user->role_type,
        ] );
    }
}
