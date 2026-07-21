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

    public function status() {
        $domainStatus = $this->customDomainService->getSavedDomainStatusForTenant( tenant()->id );

        if ( ! ( $domainStatus['saved'] ?? false ) ) {
            return response()->json( [
                'status'  => 200,
                'active'  => false,
                'saved'   => false,
                'domain'  => null,
                'message' => 'No custom domain saved.',
            ] );
        }

        return response()->json( [
            'status'            => 200,
            'active'            => $domainStatus['active'],
            'saved'             => true,
            'domain'            => $domainStatus['domain'],
            'connection_status' => $domainStatus['connection_status'],
            'verification'      => $domainStatus['verification'],
            'ssl'               => $domainStatus['ssl'],
        ] );
    }

    public function lookup( Request $request ) {
        $domain = $request->input( 'domain', $request->query( 'domain' ) );

        $validator = Validator::make( ['domain' => $domain], [
            'domain' => ['required', 'string', 'max:255', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i'],
        ] );

        if ( $validator->fails() ) {
            return response()->json( [
                'status'  => 422,
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422 );
        }

        $result = $this->customDomainService->lookupCustomDomain( (string) $domain );

        if ( ! $result ) {
            return response()->json( [
                'status'    => 404,
                'connected' => false,
                'message'   => 'Custom domain is not registered to any tenant.',
            ], 404 );
        }

        return response()->json( [
            'status'    => 200,
            'connected' => true,
            'data'      => $result,
        ] );
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
