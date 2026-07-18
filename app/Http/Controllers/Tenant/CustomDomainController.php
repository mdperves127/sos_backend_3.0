<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\CustomDomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomDomainController extends Controller
{
    public function __construct(
        private CustomDomainService $customDomainService
    ) {
    }

    public function show() {
        $record = $this->customDomainService->forTenant( tenant()->id );

        if ( ! $record ) {
            return response()->json( [
                'status'  => 200,
                'message' => 'No custom domain configured yet.',
                'domain'  => null,
                'dns'     => null,
                'instructions' => [
                    'type'  => 'A',
                    'host'  => '@',
                    'value' => $this->customDomainService->targetIp(),
                    'note'  => 'Add a custom domain first, then point DNS to this IP.',
                ],
            ] );
        }

        return response()->json( [
            'status' => 200,
            ...$this->customDomainService->checkConnection( $record ),
        ] );
    }

    public function status() {
        $record = $this->customDomainService->forTenant( tenant()->id );

        if ( ! $record ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No custom domain configured yet.',
            ], 404 );
        }

        $payload = $this->customDomainService->checkConnection( $record );

        return response()->json( [
            'status' => 200,
            ...$payload,
        ] );
    }

    public function store( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'domain' => ['required', 'string', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i'],
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 422,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422 );
        }

        $domain = $this->customDomainService->normalizeDomain( $request->input( 'domain' ) );

        $exists = \App\Models\TenantCustomDomain::on( 'mysql' )
            ->where( 'domain', $domain )
            ->where( 'tenant_id', '!=', tenant()->id )
            ->exists();

        if ( $exists ) {
            return response()->json( [
                'status'  => 409,
                'message' => 'This domain is already connected to another tenant.',
            ], 409 );
        }

        $record  = $this->customDomainService->addDomain( tenant()->id, $domain );
        $payload = $this->customDomainService->checkConnection( $record );

        return response()->json( [
            'status'  => 201,
            'message' => 'Custom domain added. Update DNS, then verify ownership.',
            ...$payload,
        ], 201 );
    }

    public function verify() {
        $record = $this->customDomainService->forTenant( tenant()->id );

        if ( ! $record ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No custom domain configured yet.',
            ], 404 );
        }

        $payload = $this->customDomainService->verifyDomain( $record );

        return response()->json( [
            'status' => $payload['success'] ? 200 : 422,
            ...$payload,
        ], $payload['success'] ? 200 : 422 );
    }

    public function activate() {
        $record = $this->customDomainService->forTenant( tenant()->id );

        if ( ! $record ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No custom domain configured yet.',
            ], 404 );
        }

        $payload = $this->customDomainService->activateDomain( $record );

        return response()->json( [
            'status' => $payload['success'] ? 200 : 422,
            ...$payload,
        ], $payload['success'] ? 200 : 422 );
    }

    public function resolve( Request $request ) {
        $validator = Validator::make( $request->all(), [
            'host' => 'required|string|max:255',
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 422,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422 );
        }

        $resolved = $this->customDomainService->resolveHost( $request->input( 'host' ) );

        if ( ! $resolved ) {
            return response()->json( [
                'status'  => 404,
                'message' => 'No active tenant found for this host.',
            ], 404 );
        }

        return response()->json( [
            'status' => 200,
            'data'   => $resolved,
        ] );
    }
}
