<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\CpanelService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CpanelController extends Controller
{
    protected CpanelService $cpanelService;

    public function __construct(CpanelService $cpanelService)
    {
        $this->cpanelService = $cpanelService;
    }

    /**
     * Create subdomain
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createSubdomain(Request $request): JsonResponse
    {
        $request->validate([
            'subdomain' => 'required|string|max:255'
        ]);

        try {
            $result = $this->cpanelService->createSubdomain($request->subdomain);

            return response()->json([
                'success' => true,
                'message' => 'Subdomain operation completed',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create subdomain',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create database
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createDatabase(Request $request): JsonResponse
    {
        $request->validate([
            'dbname' => 'required|string|max:255'
        ]);

        try {
            $result = $this->cpanelService->createDatabase($request->dbname);

            return response()->json([
                'success' => true,
                'message' => 'Database operation completed',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create database',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create tenant infrastructure (both subdomain and database)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createTenantInfrastructure(Request $request): JsonResponse
    {
        $request->validate([
            'subdomain' => 'required|string|max:255',
            'dbname' => 'required|string|max:255'
        ]);

        try {
            $result = $this->cpanelService->createTenantInfrastructure($request->subdomain, $request->dbname);

            return response()->json([
                'success' => true,
                'message' => 'Tenant infrastructure creation completed',
                'data' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create tenant infrastructure',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current environment information
     *
     * @return JsonResponse
     */
    public function getEnvironmentInfo(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'environment' => env('APP_ENV'),
                'app_url' => env('APP_URL'),
                'cpanel_configured' => !empty(env('CPANEL_USER')) && !empty(env('CPANEL_PASSWORD')) && !empty(env('CPANEL_HOST')),
                'main_domain' => env('MAIN_DOMAIN'),
                'db_username' => env('DB_USERNAME'),
                'db_password_configured' => !empty(env('DB_PASSWORD'))
            ]
        ]);
    }
}
