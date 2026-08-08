<?php

namespace App\Http\Controllers;

use App\Services\TenantVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TenantVerificationController extends Controller
{
    public function __construct(
        protected TenantVerificationService $verificationService
    ) {
    }

    public function send( Request $request ): JsonResponse
    {
        try {
            $payload = $this->validateChannelPayload( $request );
            $result  = $this->verificationService->send(
                $payload['channel'],
                $payload['email'] ?? null,
                $payload['phone'] ?? null
            );
        } catch ( ValidationException $e ) {
            return $this->validationErrorResponse( $e );
        }

        return response()->json( [
            'success' => true,
            'message' => 'Verification code sent successfully.',
            'data'    => $result,
        ] );
    }

    public function verify( Request $request ): JsonResponse
    {
        try {
            $payload = $this->validateChannelPayload( $request, true );
            $result  = $this->verificationService->verify(
                $payload['channel'],
                $payload['code'],
                $payload['email'] ?? null,
                $payload['phone'] ?? null
            );
        } catch ( ValidationException $e ) {
            return $this->validationErrorResponse( $e );
        }

        return response()->json( [
            'success' => true,
            'message' => 'Verification successful.',
            'data'    => $result,
        ] );
    }

    public function resend( Request $request ): JsonResponse
    {
        try {
            $payload = $this->validateChannelPayload( $request );
            $result  = $this->verificationService->resend(
                $payload['channel'],
                $payload['email'] ?? null,
                $payload['phone'] ?? null
            );
        } catch ( ValidationException $e ) {
            return $this->validationErrorResponse( $e );
        }

        return response()->json( [
            'success' => true,
            'message' => 'Verification code resent successfully.',
            'data'    => $result,
        ] );
    }

    private function validateChannelPayload( Request $request, bool $requireCode = false ): array
    {
        $rules = [
            'channel' => 'required|in:email,sms,phone',
            'email'   => 'required_if:channel,email|nullable|email|max:255',
            'phone'   => 'required_if:channel,sms,phone|nullable|string|max:20',
        ];

        if ( $requireCode ) {
            $rules['code'] = 'required|digits:6';
        }

        $validator = Validator::make( $request->all(), $rules, [
            'email.required_if' => 'Email is required for email verification.',
            'phone.required_if' => 'Phone number is required for SMS verification.',
            'code.required'     => 'Verification code is required.',
            'code.digits'       => 'Verification code must be 6 digits.',
        ] );

        if ( $validator->fails() ) {
            throw ValidationException::withMessages( $validator->errors()->toArray() );
        }

        return $validator->validated() + [
            'channel' => $request->input( 'channel' ) === 'phone' ? 'sms' : $request->input( 'channel' ),
        ];
    }

    private function validationErrorResponse( ValidationException $e ): JsonResponse
    {
        return response()->json( [
            'success' => false,
            'message' => 'Validation failed.',
            'errors'  => $e->errors(),
        ], 422 );
    }
}
