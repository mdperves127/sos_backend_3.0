<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\TenantRegistrationRequest;
use App\Services\TenantService;
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

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
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
            $result = $this->tenantService->createTenant($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Tenant registered successfully',
                'data' => [
                    'tenant_id' => $result['tenant_id'],
                    'domain' => $request->domain,
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
}
