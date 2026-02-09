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
    public function reset( Request $request ): JsonResponse
    {
        $validator = Validator::make( $request->all(), [
            'email'    => 'required|email',
            'token'    => 'required|integer',
            'password' => 'required|confirmed|min:8',
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

        // Check if the provided token exists in the tenant's password_resets table
        $tokenData = DB::connection( 'tenant' )
            ->table( 'password_resets' )
            ->where( 'email', $request->email )
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
                ->where( 'email', $request->email )
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

        // Find user in tenant database and update password
        $user = User::on( 'tenant' )->where( 'email', $request->email )->first();

        if ( ! $user ) {
            return response()->json( [
                'status'  => 400,
                'message' => 'User not found',
            ], 400 );
        }

        $user->forceFill( [
            'password' => Hash::make( $request->password ),
        ] )->save();

        // Delete the used token from password_resets
        DB::connection( 'tenant' )
            ->table( 'password_resets' )
            ->where( 'email', $request->email )
            ->delete();

        return response()->json( [
            'status'  => 200,
            'message' => 'Password reset successfully. You can now login with your new password.',
        ] );
    }
}
