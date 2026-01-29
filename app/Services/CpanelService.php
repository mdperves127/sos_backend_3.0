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
        try {
            $cpanelUser = env('CPANEL_USER');
            $cpanelPassword = env('CPANEL_PASSWORD');
            $cpanelHost = env('CPANEL_HOST'); // e.g., cpanel.example.com
            $mainDomain = env('MAIN_DOMAIN'); // e.g., example.com

            // Validate required environment variables
            if (empty($cpanelUser) || empty($cpanelPassword) || empty($cpanelHost) || empty($mainDomain)) {
                \Log::error('cPanel subdomain creation: Missing required environment variables', [
                    'has_user' => !empty($cpanelUser),
                    'has_password' => !empty($cpanelPassword),
                    'has_host' => !empty($cpanelHost),
                    'has_main_domain' => !empty($mainDomain)
                ]);
                return [
                    'status' => 0,
                    'error' => 'Missing required cPanel configuration',
                    'message' => 'cPanel credentials or main domain not configured'
                ];
            }

            // Define the directory for the subdomain (point to the same directory as main app)
            $subdomainDir = env('CPANEL_TENANT_ROOT', 'public_html/');

            // URL encode parameters to handle special characters
            $subdomainEncoded = urlencode($subdomain);
            $mainDomainEncoded = urlencode($mainDomain);
            $subdomainDirEncoded = urlencode($subdomainDir);

            $url = "https://$cpanelHost:2083/json-api/cpanel?cpanel_jsonapi_user=$cpanelUser&cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=SubDomain&cpanel_jsonapi_func=addsubdomain&domain=$subdomainEncoded&rootdomain=$mainDomainEncoded&dir=$subdomainDirEncoded";

            \Log::info('cPanel subdomain creation: Making API call', [
                'subdomain' => $subdomain,
                'main_domain' => $mainDomain,
                'host' => $cpanelHost
            ]);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, "$cpanelUser:$cpanelPassword");
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false || !empty($curlError)) {
                \Log::error('cPanel subdomain creation: cURL error', [
                    'subdomain' => $subdomain,
                    'curl_error' => $curlError,
                    'http_code' => $httpCode
                ]);
                return [
                    'status' => 0,
                    'error' => 'cURL error: ' . $curlError,
                    'http_code' => $httpCode
                ];
            }

            $result = json_decode($response, true);

            \Log::info('cPanel subdomain creation: API response', [
                'subdomain' => $subdomain,
                'response' => $result,
                'http_code' => $httpCode,
                'raw_response' => $response
            ]);

            // Check various possible success indicators in cPanel API response
            $success = false;
            $errorMessage = null;

            // Check for success in different possible response structures
            if (isset($result['cpanelresult']['data'][0]['result']['status']) && $result['cpanelresult']['data'][0]['result']['status'] == 1) {
                $success = true;
            } elseif (isset($result['cpanelresult']['data'][0]['status']) && $result['cpanelresult']['data'][0]['status'] == 1) {
                $success = true;
            } elseif (isset($result['status']) && $result['status'] == 1) {
                $success = true;
            } elseif (isset($result['cpanelresult']['error'])) {
                $errorMessage = $result['cpanelresult']['error'];
            } elseif (isset($result['error'])) {
                $errorMessage = $result['error'];
            } elseif ($httpCode >= 200 && $httpCode < 300 && !isset($result['error'])) {
                // If HTTP code is success and no error field, assume success
                $success = true;
            }

            if ($success) {
                // Set PHP version for the subdomain (default: 8.2)
                $phpVersion = env('CPANEL_PHP_VERSION', '82'); // Default to PHP 8.2
                $phpVersionResult = $this->setPhpVersionForSubdomain($subdomain, $mainDomain, $phpVersion);

                \Log::info('cPanel subdomain creation: PHP version setting result', [
                    'subdomain' => $subdomain,
                    'php_version_result' => $phpVersionResult
                ]);

                return [
                    'status' => 1,
                    'message' => 'Subdomain created successfully',
                    'subdomain' => $subdomain,
                    'php_version_set' => $phpVersionResult['status'] ?? 0,
                    'php_version_message' => $phpVersionResult['message'] ?? '',
                    'full_response' => $result
                ];
            } else {
                \Log::error('cPanel subdomain creation: Failed', [
                    'subdomain' => $subdomain,
                    'error' => $errorMessage,
                    'response' => $result,
                    'http_code' => $httpCode
                ]);
                return [
                    'status' => 0,
                    'error' => $errorMessage ?: 'Unknown error from cPanel API',
                    'full_response' => $result,
                    'http_code' => $httpCode
                ];
            }
        } catch (\Exception $e) {
            \Log::error('cPanel subdomain creation: Exception', [
                'subdomain' => $subdomain,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'status' => 0,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Set PHP version for a subdomain/domain
     *
     * @param string $subdomain
     * @param string $mainDomain
     * @param string $phpVersion PHP version (e.g., '82' for 8.2, '81' for 8.1)
     * @return array
     */
    private function setPhpVersionForSubdomain($subdomain, $mainDomain, $phpVersion = '82')
    {
        try {
            $cpanelUser = env('CPANEL_USER');
            $cpanelPassword = env('CPANEL_PASSWORD');
            $cpanelHost = env('CPANEL_HOST');

            // Full domain name (subdomain.maindomain.com)
            $fullDomain = $subdomain . '.' . $mainDomain;

            // Use UAPI execute endpoint for PHP Selector
            $url = "https://$cpanelHost:2083/execute/PhpSelector/set_php_version";

            \Log::info('cPanel PHP version setting: Making API call', [
                'domain' => $fullDomain,
                'php_version' => $phpVersion
            ]);

            // Prepare POST data
            $postData = [
                'domain' => $fullDomain,
                'php_version' => $phpVersion
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, "$cpanelUser:$cpanelPassword");
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($response === false || !empty($curlError)) {
                \Log::error('cPanel PHP version setting: cURL error', [
                    'domain' => $fullDomain,
                    'php_version' => $phpVersion,
                    'curl_error' => $curlError,
                    'http_code' => $httpCode
                ]);
                return [
                    'status' => 0,
                    'error' => 'cURL error: ' . $curlError,
                    'http_code' => $httpCode
                ];
            }

            $result = json_decode($response, true);

            \Log::info('cPanel PHP version setting: API response', [
                'domain' => $fullDomain,
                'php_version' => $phpVersion,
                'response' => $result,
                'http_code' => $httpCode
            ]);

            // Check if the result indicates success
            if (isset($result['status']) && $result['status'] == 1) {
                return [
                    'status' => 1,
                    'message' => 'PHP version set to ' . $phpVersion . ' successfully',
                    'domain' => $fullDomain,
                    'php_version' => $phpVersion,
                    'full_response' => $result
                ];
            } elseif (isset($result['errors'])) {
                $errorMessage = is_array($result['errors']) ? implode(', ', $result['errors']) : $result['errors'];
                \Log::error('cPanel PHP version setting: API error', [
                    'domain' => $fullDomain,
                    'php_version' => $phpVersion,
                    'error' => $errorMessage
                ]);
                return [
                    'status' => 0,
                    'error' => $errorMessage,
                    'full_response' => $result
                ];
            } else {
                // If no clear error but status is not 1, log and return
                \Log::warning('cPanel PHP version setting: Unexpected response', [
                    'domain' => $fullDomain,
                    'php_version' => $phpVersion,
                    'response' => $result
                ]);
                return [
                    'status' => isset($result['status']) ? $result['status'] : 0,
                    'message' => 'PHP version setting completed with unexpected response',
                    'full_response' => $result
                ];
            }
        } catch (\Exception $e) {
            \Log::error('cPanel PHP version setting: Exception', [
                'domain' => $subdomain . '.' . $mainDomain,
                'php_version' => $phpVersion,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'status' => 0,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
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
