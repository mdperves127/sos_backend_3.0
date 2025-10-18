<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class TenantAuthController extends Controller {
    /**
     * Register a new user for the current tenant
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function register( Request $request ): JsonResponse {
        try {
            // Ensure we're in a tenant context
            if ( !tenant() ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Tenant context not found',
                ], 400 );
            }

            $validator = Validator::make( $request->all(), [
                'name'     => 'required|string|max:255',
                'email'    => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
            ] );

            if ( $validator->fails() ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors(),
                ], 422 );
            }

            $user = User::on('tenant')->create( [
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make( $request->password ),
            ] );

            // Generate token for the new user
            $token = $user->createToken( 'tenant-auth-token' )->plainTextToken;

            return response()->json( [
                'success' => true,
                'message' => 'User registered successfully',
                'data'    => [
                    'user'      => [
                        'id'    => $user->id,
                        'name'  => $user->name,
                        'email' => $user->email,
                    ],
                    'token'     => $token,
                    'tenant_id' => tenant( 'id' ),
                ],
            ], 201 );

        } catch ( \Exception $e ) {
            return response()->json( [
                'success' => false,
                'message' => 'Failed to register user',
                'error'   => $e->getMessage(),
            ], 500 );
        }
    }

    /**
     * Login user for the current tenant
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function login( Request $request ): JsonResponse {
        try {
            // Ensure we're in a tenant context
            if ( !tenant() ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Tenant context not found',
                ], 400 );
            }

            $validator = Validator::make( $request->all(), [
                'email'    => 'required|string|email',
                'password' => 'required|string',
            ] );

            if ( $validator->fails() ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors(),
                ], 422 );
            }

            // Check if user exists and credentials are correct
            // Force using tenant connection
            $user = User::on('tenant')->where( 'email', $request->email )->first();

            if ( !$user || !Hash::check( $request->password, $user->password ) ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Invalid credentials',
                ], 401 );
            }

            // Update last seen
            $user->update( ['last_seen' => now()] );

            // Generate token
            $token = $user->createToken( 'tenant-auth-token' )->plainTextToken;

            return response()->json( [
                'success' => true,
                'message' => 'Login successful',
                'data'    => [
                    'user'        => [
                        'id'        => $user->id,
                        'name'      => $user->name,
                        'email'     => $user->email,
                        'last_seen' => $user->last_seen,
                    ],
                    'token'       => $token,
                    'tenant_id'   => tenant( 'id' ),
                    'tenant_type' => tenant( 'type' ),
                ],
            ] );

        } catch ( \Exception $e ) {
            return response()->json( [
                'success' => false,
                'message' => 'Failed to login',
                'error'   => $e->getMessage(),
            ], 500 );
        }
    }

    /**
     * Logout user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout( Request $request ): JsonResponse {
        try {
            // Ensure we're in a tenant context
            if ( !tenant() ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Tenant context not found',
                ], 400 );
            }

            // Get the bearer token
            $bearerToken = $request->bearerToken();
            if ( !$bearerToken ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'No bearer token provided',
                ], 401 );
            }

            // Parse the token (format: "4|token...")
            $tokenParts = explode( '|', $bearerToken );
            if ( count( $tokenParts ) !== 2 ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Invalid token format',
                ], 401 );
            }

            $tokenId = $tokenParts[0];

            // Find and delete the token
            $token = \App\Models\PersonalAccessToken::find( $tokenId );
            if ( $token ) {
                $token->delete();
            }

            return response()->json( [
                'success' => true,
                'message' => 'Logged out successfully',
            ] );

        } catch ( \Exception $e ) {
            return response()->json( [
                'success' => false,
                'message' => 'Failed to logout',
                'error'   => $e->getMessage(),
            ], 500 );
        }
    }

    public function profileInfo( Request $request ): JsonResponse {
        $user = User::on('tenant')->find( Auth::user()->id );
        return response()->json( [
            'success'     => true,
            'message'     => 'Profile info fetched successfully',
            'data'        => $user,
            'tenant_type' => tenant( 'type' ),
        ] );
    }

    /**
     * Update user profile
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile( Request $request ): JsonResponse {
        try {
            // Ensure we're in a tenant context
            if ( !tenant() ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Tenant context not found',
                ], 400 );
            }

            $user = $request->user();

            $validator = Validator::make( $request->all(), [
                'name'  => 'sometimes|string|max:255',
                'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            ] );

            if ( $validator->fails() ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors(),
                ], 422 );
            }

            $user->update( $request->only( ['name', 'email', 'number', 'image'] ) );

            return response()->json( [
                'success' => true,
                'message' => 'Profile updated successfully',
                'data'    => [
                    'user' => [
                        'id'    => $user->id,
                        'name'  => $user->name,
                        'email' => $user->email,
                    ],
                ],
            ] );

        } catch ( \Exception $e ) {
            return response()->json( [
                'success' => false,
                'message' => 'Failed to update profile',
                'error'   => $e->getMessage(),
            ], 500 );
        }
    }

    /**
     * Change password
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function changePassword( Request $request ): JsonResponse {
        try {
            // Ensure we're in a tenant context
            if ( !tenant() ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Tenant context not found',
                ], 400 );
            }

            $user = $request->user();

            $validator = Validator::make( $request->all(), [
                'current_password' => 'required|string',
                'new_password'     => 'required|string|min:8',
            ] );

            if ( $validator->fails() ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors(),
                ], 422 );
            }

            // Check current password
            if ( !Hash::check( $request->current_password, $user->password ) ) {
                return response()->json( [
                    'success' => false,
                    'message' => 'Current password is incorrect',
                ], 400 );
            }

            // Update password
            $user->update( [
                'password' => Hash::make( $request->new_password ),
            ] );

            return response()->json( [
                'success' => true,
                'message' => 'Password changed successfully',
            ] );

        } catch ( \Exception $e ) {
            return response()->json( [
                'success' => false,
                'message' => 'Failed to change password',
                'error'   => $e->getMessage(),
            ], 500 );
        }
    }
    public function profileData( Request $request ): JsonResponse {
        $tenant_data = tenant()->id;
        return response()->json( [
            'success'     => true,
            'message'     => 'Profile data fetched successfully',
            'data'        => $tenant_data,
        ] );
    }
}
