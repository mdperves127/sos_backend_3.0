<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenantRegistrationRequest;
use App\Services\TenantService;
use App\Services\CustomDomainService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;
use App\Models\User;

class TenantRegistrationController extends Controller
{
    protected TenantService $tenantService;
    protected CustomDomainService $customDomainService;

    public function __construct(TenantService $tenantService, CustomDomainService $customDomainService)
    {
        $this->tenantService = $tenantService;
        $this->customDomainService = $customDomainService;
    }

    /**
     * Register a new tenant
     *
     * @param TenantRegistrationRequest $request
     * @return JsonResponse
     */
    public function register(TenantRegistrationRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['status'] = $data['status'] ?? 'pending';
            $result = $this->tenantService->createTenant( $data );
            // dd($result);

            return response()->json([
                'success' => true,
                'message' => 'Tenant registered successfully',
                'data' => [
                    'tenant_id' => $result['tenant_id'],
                    'domain' => $request->domain,
                    'type' => $request->type ?? 'dropshipper',
                    'company_name' => $request->company_name,
                    'email' => $request->email,
                    'domain_url' => $result['domain_url'],
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to register tenant',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function haveTenant( $tenant )
    {
        $resolved = $this->customDomainService->resolveTenantByIdentifier( (string) $tenant );

        if ( ! $resolved ) {
            return response()->json([
                'success' => false,
                'message' => 'No tenant found for this tenant id or custom domain.',
            ], 404);
        }

        return response()->json([
            'success'           => true,
            'message'           => 'Tenant found successfully',
            'matched_by'        => $resolved['matched_by'],
            'tenant_id'         => $resolved['tenant_id'],
            'subdomain'         => $resolved['subdomain'],
            'subdomain_name'    => $resolved['subdomain_name'],
            'has_custom_domain' => $resolved['has_custom_domain'],
            'custom_domain'     => $resolved['custom_domain'],
        ]);
    }
}
