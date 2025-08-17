<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\UserVerifyNotification;
use App\Services\SmsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller {

    protected $smsService;

    public function __construct( SmsService $smsService ) {
        $this->smsService = $smsService;
    }

    public function Register( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'name'     => 'required|max:191',
            'email'    => 'required|email|max:191|unique:users,email',
            'password' => 'required|min:8',
            'number'   => 'required|min:10|max:13|unique:users,number',
            'role'     => 'required',
        ] );
        if ( $validator->fails() ) {
            return response()->json( [
                'status'            => 400,
                'validation_errors' => $validator->messages(),
            ] );
        } else {
            if ( $request->role == 1 ) {
                return response()->json( [
                    'validation_errors' => 'Admin can not register',
                ] );
            }

            $user = User::create( [
                'name'              => $request->name,
                'email'             => $request->email,
                'role_as'           => $request->role,
                'number'            => $request->number,
                'status'            => 'pending',
                'password'          => Hash::make( $request->password ),
                'status'            => 'active',
                'uniqid'            => uniqid(),
                'last_seeen'        => now(),
                'verify_code'       => rand( 100000, 999999 ),
                'verify_code_at'    => now(),
                'email_verified_at' => otpType() == "off" ? now() : NULL,
            ] );

            if ( otpType() == "sms" ) {
                SmsService::sendSms( $user );
            } elseif ( otpType() == "email" ) {
                Notification::send( $user, new UserVerifyNotification( $user ) );
            }

            $otpType = otpType();
            return response()->json( [
                'status'      => 200,
                'message'     => 'Registration successfully!',
                'userId'      => $user->email,
                'verify_type' => ( $otpType == "sms" ) ? ' phone' : (  ( $otpType == "email" ) ? ' email' : 'off' ),
                'send_to'     => ( $otpType == "sms" ) ? $request->number : (  ( $otpType == "email" ) ? $request->email : 'off' ),
            ] );
        }
    }

    public function verify( Request $request ) {
        // dd($request->all());
        $user = User::where( 'verify_code', '=', $request->verify_code )->first();

        if ( $user == null ) {
            return response()->json( [
                'status'  => 402,
                'message' => 'Invalid Code !',
            ] );
        }

        $expiredTime = Carbon::parse( $user->verify_code_at )->addMinutes( 5 );

        if ( $expiredTime->isPast() ) {
            return response()->json( [
                'status'  => 402,
                'message' => 'Verify code is expired!',
            ] );
        }

        $user->email_verified_at = now();
        $user->save();

        $token = $user->createToken( 'API TOKEN' )->plainTextToken;

        return response()->json( [
            'status'      => 200,
            'username'    => $user->name,
            'user_status' => $user->status,
            'token'       => $token,
            'message'     => 'Verify Successfully',
            'role'        => $user->role_as,
        ] );

    }

    public function resendVerifyCode( Request $request ) {
        $user                 = User::where( 'email', $request->email )->first();
        $user->verify_code    = rand( 100000, 999999 );
        $user->verify_code_at = now();
        $user->save();

        if ( otpType() == "sms" ) {
            SmsService::sendSms( $user );
        } elseif ( otpType() == "email" ) {
            Notification::send( $user, new UserVerifyNotification( $user ) );
        }

        return response()->json( [
            'status'  => 200,
            'message' => 'OTP Sent. Please check your ' . ( otpType() == "sms" ? ' SMS' : ' Email' ) . '!',
            // 'email' => $user->email,
        ] );
    }

    // public function login(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'email' => 'required|max:191',
    //         'password' => 'required',
    //     ]);

    //     if ($validator->fails()) {
    //         return response()->json([
    //             'validation_errors' => $validator->messages(),
    //         ]);
    //     } else {
    //         $user = User::where('email', $request->email)->first();

    //         if (!$user || !Hash::check($request->password, $user->password)) {
    //             return response()->json([
    //                 'status' => 401,
    //                 'message' => 'Invalid Credentials',
    //             ]);
    //         }

    //         if ($user->status !== 'active') {
    //             return response()->json([
    //                 'status' => 401,
    //                 'message' => 'Account is inactive please Contact with Admin!',
    //             ]);
    //         } else {
    //             if ($user->role_as == 1) //1= Admin
    //             {
    //                 $token = $user->createToken('API TOKEN')->plainTextToken;
    //             } else if ($user->role_as == 2) //vendor
    //             {
    //                 $token = $user->createToken('API TOKEN')->plainTextToken;
    //             } else //af
    //             {
    //                 $token = $user->createToken('API TOKEN')->plainTextToken;
    //             }
    //             $user->last_seen = now();
    //             $user->save();

    //             return response()->json([
    //                 'status' => 200,
    //                 'username' => $user->name,
    //                 'user_status'=>$user->status,
    //                 'token' => $token,
    //                 'message' => 'Logged In Successfully',
    //                 'role' => $user->role_as,
    //                 'is_subscription'=>$user?->usersubscription?->id
    //             ]);
    //         }
    //     }
    // }

    public function login( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'login' => 'required|max:191', // 'login' can be either email or phone number
            'password' => 'required',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'validation_errors' => $validator->messages(),
            ] );
        } else {
            $loginField = filter_var( $request->login, FILTER_VALIDATE_EMAIL ) ? 'email' : 'number';

            $user = User::where( $loginField, $request->login )->first();

            if ( !$user || !Hash::check( $request->password, $user->password ) ) {
                return response()->json( [
                    'status'  => 401,
                    'message' => 'Invalid Credentials',
                ] );
            }

            if ( $user->status == 'blocked' ) {
                return response()->json( [
                    'status'  => 401,
                    'message' => 'Account is blocked please Contact with Admin!',
                ] );
            }else

            if ( $user->status !== 'active' ) {
                return response()->json( [
                    'status'  => 401,
                    'message' => 'Account is inactive please Contact with Admin!',
                ] );
            }


            if ( !$user->email_verified_at == null ) {
                // Generate token based on user role
                $token = $user->createToken( 'API TOKEN' )->plainTextToken;

                // Update user's last_seen timestamp
                $user->last_seen = now();
                $user->save();

                return response()->json( [
                    'status'          => 200,
                    'username'        => $user->name,
                    'user_status'     => $user->status,
                    'token'           => $token,
                    'message'         => 'Logged In Successfully',
                    'role'            => $user->role_as,
                    'is_subscription' => $user->usersubscription ? $user->usersubscription->id : null,
                    'is_employee' => $user->is_employee,
                ] );
            } else {

                $user->verify_code    = rand( 100000, 999999 );
                $user->verify_code_at = now();
                $user->save();

                if ( otpType() == "sms" ) {
                    SmsService::sendSms( $user );
                } elseif ( otpType() == "email" ) {
                    Notification::send( $user, new UserVerifyNotification( $user ) );
                }

                $otpType = otpType();
                return response()->json( [
                    'status'      => 400,
                    'message'     => 'Your account is not verified. We have sent a verification code to your ' . ( otpType() == "sms" ? ' SMS' : ' Email address ' ) . '!',
                    'verify_type' => ( $otpType == "sms" ) ? ' phone' : (  ( $otpType == "email" ) ? ' email' : 'off' ),
                    'send_to'     => ( $otpType == "sms" ) ? $user->number : (  ( $otpType == "email" ) ? $user->email : 'off' ),
                ] );
            }

        }
    }
}
