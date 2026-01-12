<?php

namespace App\Services;

use Exception;

class CpanelService
{
    /**
     * Create subdomain based on environment
     *
     * @param string $subdomain
     * @return array
     */
    public function createSubdomain($subdomain)
    {
        // Check environment
        if (env('APP_ENV') === 'local') {
            // For local environment, just return success without actual cPanel operations
            return [
                'status' => 1,
                'message' => 'Subdomain creation skipped for local environment',
                'subdomain' => $subdomain,
                'environment' => 'local'
            ];
        } elseif (env('APP_ENV') === 'production') {
            // For production environment, use cPanel API
            return $this->createSubdomainViaCpanel($subdomain);
        } else {
            // For other environments, you can add custom logic here
            return [
                'status' => 0,
                'message' => 'Unsupported environment: ' . env('APP_ENV'),
                'environment' => env('APP_ENV')
            ];
        }
    }

    /**
     * Create database based on environment
     *
     * @param string $dbname
     * @return array
     */
    public function createDatabase($dbname)
    {
        // Check environment
        if (env('APP_ENV') === 'local') {
            // For local environment, just return success without actual cPanel operations
            return [
                'status' => 1,
                'message' => 'Database creation skipped for local environment',
                'database' => $dbname,
                'environment' => 'local'
            ];
        } elseif (env('APP_ENV') === 'production') {
            // For production environment, use cPanel API
            return $this->createDatabaseViaCpanel($dbname);
        } else {
            // For other environments, you can add custom logic here
            return [
                'status' => 0,
                'message' => 'Unsupported environment: ' . env('APP_ENV'),
                'environment' => env('APP_ENV')
            ];
        }
    }

    /**
     * Create subdomain using cPanel API
     *
     * @param string $subdomain
     * @return array
     */
    private function createSubdomainViaCpanel($subdomain)
    {
        $cpanelUser = env('CPANEL_USER');
        $cpanelPassword = env('CPANEL_PASSWORD');
        $cpanelHost = env('CPANEL_HOST'); // e.g., cpanel.example.com
        $mainDomain = env('MAIN_DOMAIN'); // e.g., example.com

        // Define the directory for the subdomain (point to the same directory as main app)
        $subdomainDir = env('CPANEL_TENANT_ROOT', 'public_html/storeeb.com/public');

        $url = "https://$cpanelHost:2083/json-api/cpanel?cpanel_jsonapi_user=$cpanelUser&cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=SubDomain&cpanel_jsonapi_func=addsubdomain&domain=$subdomain&rootdomain=$mainDomain&dir=$subdomainDir";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$cpanelUser:$cpanelPassword");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }

    /**
     * Create database using cPanel API
     *
     * @param string $dbname
     * @return array
     */
    private function createDatabaseViaCpanel($dbname)
    {
        $cpanelUser = env('CPANEL_USER');
        $cpanelPassword = env('CPANEL_PASSWORD');
        $cpanelHost = env('CPANEL_HOST');

        // Fixed username and password
        $dbUsername = env('DB_USERNAME'); // Fixed username
        $dbPassword = env('DB_PASSWORD'); // Fixed password (change this to a secure password)

        // Step 1: Create the database
        $createDbUrl = "https://$cpanelHost:2083/execute/Mysql/create_database?name=$dbname";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $createDbUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$cpanelUser:$cpanelPassword");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $createDbResponse = curl_exec($ch);
        curl_close($ch);

        $createDbResult = json_decode($createDbResponse, true);

        // Check if creation was successful OR if it failed (likely because it already exists). 
        // We attempt to assign the user in either case to ensure permissions are correct (Idempotency).
        
        // Step 3: Assign the user to the database
        $assignUserUrl = "https://$cpanelHost:2083/execute/Mysql/set_privileges_on_database?user=$dbUsername&database=$dbname&privileges=ALL";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $assignUserUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "$cpanelUser:$cpanelPassword");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $assignUserResponse = curl_exec($ch);
        curl_close($ch);

        $assignUserResult = json_decode($assignUserResponse, true);

        // Return combined result
        return [
            'database' => $createDbResult,
            'assignment' => $assignUserResult,
            'status' => (isset($createDbResult['status']) && $createDbResult['status'] == 1) || (isset($assignUserResult['status']) && $assignUserResult['status'] == 1) ? 1 : 0
        ];
    }

    /**
     * Create both subdomain and database for a tenant
     *
     * @param string $subdomain
     * @param string $dbname
     * @return array
     */
    public function createTenantInfrastructure($subdomain, $dbname)
    {
        try {
            $subdomainResult = $this->createSubdomain($subdomain);
            $databaseResult = $this->createDatabase($dbname);

            return [
                'subdomain' => $subdomainResult,
                'database' => $databaseResult,
                'environment' => env('APP_ENV'),
                'success' => true
            ];
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'environment' => env('APP_ENV'),
                'success' => false
            ];
        }
    }
}
