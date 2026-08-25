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
        return $this->createSubdomainViaCpanel( $subdomain );
    }

    /**
     * Create database via cPanel API.
     *
     * @param string $dbname
     * @return array
     */
    public function createDatabase($dbname)
    {
        return $this->createDatabaseViaCpanel( $dbname );
    }

    /**
     * cPanel credentials from config (safe with config:cache).
     *
     * @return array{user: string, password: string, host: string, main_domain: string}
     */
    private function credentials(): array
    {
        return [
            'user'         => (string) config( 'cpanel.user', '' ),
            'password'     => (string) config( 'cpanel.password', '' ),
            'host'         => (string) config( 'cpanel.host', '' ),
            'main_domain'  => (string) config( 'cpanel.main_domain', '' ),
        ];
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
            $creds          = $this->credentials();
            $cpanelUser     = $creds['user'];
            $cpanelPassword = $creds['password'];
            $cpanelHost     = $creds['host'];
            $mainDomain     = $creds['main_domain'];

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
            $subdomainDir = config( 'cpanel.tenant_root', 'public_html/' );
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
                // Set PHP version for the subdomain (production: default PHP 8.2, cPanel format: ea-php82)
                $phpVersion = (string) config( 'cpanel.php_version', 'ea-php82' );
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
     * Set PHP version for a subdomain/domain via cPanel LangPHP (EasyApache 4).
     *
     * @param string $subdomain
     * @param string $mainDomain
     * @param string $phpVersion PHP version: cPanel format (e.g. 'ea-php82', 'ea-php81') or short '82'/'81'
     * @return array
     */
    private function setPhpVersionForSubdomain($subdomain, $mainDomain, $phpVersion = 'ea-php82')
    {
        try {
            $creds          = $this->credentials();
            $cpanelUser     = $creds['user'];
            $cpanelPassword = $creds['password'];
            $cpanelHost     = $creds['host'];

            // Full domain name (subdomain.maindomain.com) = vhost name
            $fullDomain = $subdomain . '.' . $mainDomain;

            // cPanel expects ea-phpXX format (e.g. ea-php82). Normalize if env has short form like "82"
            if (preg_match('/^\d{2}$/', $phpVersion)) {
                $phpVersion = 'ea-php' . $phpVersion;
            }

            // UAPI: LangPHP php_set_vhost_versions (required for EasyApache 4; overrides "Inherited")
            $url = "https://$cpanelHost:2083/execute/LangPHP/php_set_vhost_versions";

            \Log::info('cPanel PHP version setting: Making API call', [
                'vhost' => $fullDomain,
                'php_version' => $phpVersion
            ]);

            $postData = [
                'vhost' => $fullDomain,
                'version' => $phpVersion
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
                'vhost' => $fullDomain,
                'php_version' => $phpVersion,
                'response' => $result,
                'http_code' => $httpCode
            ]);

            // LangPHP returns result under 'result' key with result.status
            $status = $result['result']['status'] ?? $result['status'] ?? 0;
            $errors = $result['result']['errors'] ?? $result['errors'] ?? null;

            if ($status == 1) {
                return [
                    'status' => 1,
                    'message' => 'PHP version set to ' . $phpVersion . ' successfully',
                    'domain' => $fullDomain,
                    'php_version' => $phpVersion,
                    'full_response' => $result
                ];
            }

            if ($errors) {
                $errorMessage = is_array($errors) ? implode(', ', $errors) : $errors;
                \Log::error('cPanel PHP version setting: API error', [
                    'vhost' => $fullDomain,
                    'php_version' => $phpVersion,
                    'error' => $errorMessage
                ]);
                return [
                    'status' => 0,
                    'error' => $errorMessage,
                    'full_response' => $result
                ];
            }

            \Log::warning('cPanel PHP version setting: Unexpected response', [
                'vhost' => $fullDomain,
                'php_version' => $phpVersion,
                'response' => $result
            ]);
            return [
                'status' => 0,
                'message' => 'PHP version setting completed with unexpected response',
                'full_response' => $result
            ];
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
     * Resolve the MySQL database prefix used by this cPanel account.
     * Prefers live Mysql::get_restrictions; falls back to tenancy config / CPANEL_USER.
     */
    public function getMysqlPrefix(): string
    {
        static $cachedPrefix = null;

        if ( is_string( $cachedPrefix ) && $cachedPrefix !== '' ) {
            return $cachedPrefix;
        }

        $configured = (string) config( 'tenancy.database.prefix', '' );
        $cpanelUser = (string) config( 'cpanel.user', '' );

        try {
            $result = $this->cpanelExecute( 'Mysql/get_restrictions' );
            $prefix = $result['data']['prefix']
                ?? $result['result']['data']['prefix']
                ?? null;

            if ( is_string( $prefix ) && $prefix !== '' ) {
                $cachedPrefix = $prefix;
                \Log::info( 'cPanel MySQL prefix resolved', [
                    'prefix'           => $prefix,
                    'configured'       => $configured,
                    'prefix_mismatch'  => $configured !== '' && $configured !== $prefix,
                ] );

                return $cachedPrefix;
            }
        } catch ( \Throwable $e ) {
            \Log::warning( 'cPanel get_restrictions failed; using configured prefix', [
                'error' => $e->getMessage(),
            ] );
        }

        if ( $configured !== '' ) {
            $cachedPrefix = $configured;

            return $cachedPrefix;
        }

        // cPanel prefix is typically first 8 chars of the account username + underscore.
        $base         = $cpanelUser !== '' ? substr( $cpanelUser, 0, 8 ) : 'tenant';
        $cachedPrefix = $base . '_';

        return $cachedPrefix;
    }

    /**
     * Create database using cPanel API
     *
     * @param string $dbname Full or short database name
     * @return array
     */
    private function createDatabaseViaCpanel($dbname)
    {
        $creds          = $this->credentials();
        $cpanelUser     = $creds['user'];
        $cpanelPassword = $creds['password'];
        $cpanelHost     = $creds['host'];

        if ( empty( $cpanelUser ) || empty( $cpanelPassword ) || empty( $cpanelHost ) ) {
            \Log::error( 'cPanel database creation: Missing required environment variables', [
                'has_user'     => $cpanelUser !== '',
                'has_password' => $cpanelPassword !== '',
                'has_host'     => $cpanelHost !== '',
            ] );

            return [
                'database'   => ['status' => 0, 'errors' => ['Missing cPanel configuration']],
                'assignment' => ['status' => 0],
                'status'     => 0,
                'full_name'  => (string) $dbname,
                'error'      => 'Missing cPanel configuration (CPANEL_USER / CPANEL_PASSWORD / CPANEL_HOST). If config is cached, run: php artisan config:clear && php artisan config:cache. Quote passwords with special chars in .env.',
            ];
        }

        $cpanelPrefix   = $this->getMysqlPrefix();
        $configuredPref = (string) config( 'tenancy.database.prefix', $cpanelPrefix );
        $rawName        = (string) $dbname;

        // Strip whichever known prefix is present so cPanel can re-apply its own.
        $dbNameForApi = $rawName;
        foreach ( array_unique( array_filter( [ $cpanelPrefix, $configuredPref ] ) ) as $prefix ) {
            if ( $prefix !== '' && str_starts_with( $dbNameForApi, $prefix ) ) {
                $dbNameForApi = substr( $dbNameForApi, strlen( $prefix ) );
                break;
            }
        }

        $fullDbName = $cpanelPrefix . $dbNameForApi;
        $dbUsername = (string) config( 'database.connections.mysql.username', '' );

        // Step 1: Create the database (name WITHOUT account prefix).
        $createDbResult = $this->cpanelExecute(
            'Mysql/create_database',
            [ 'name' => $dbNameForApi ]
        );

        $createOk     = $this->isCpanelSuccess( $createDbResult );
        $alreadyExists = $this->cpanelErrorsContain( $createDbResult, [ 'exists', 'already' ] );

        \Log::info( 'cPanel database creation response', [
            'requested_name' => $dbNameForApi,
            'full_db_name'   => $fullDbName,
            'create_ok'      => $createOk,
            'already_exists' => $alreadyExists,
            'result'         => $createDbResult,
        ] );

        if ( ! $createOk && ! $alreadyExists ) {
            $error = $this->extractCpanelError( $createDbResult ) ?: 'cPanel create_database failed';

            return [
                'database'   => $createDbResult,
                'assignment' => ['status' => 0],
                'status'     => 0,
                'full_name'  => $fullDbName,
                'error'      => $error,
            ];
        }

        // Step 2: Grant privileges (FULL prefixed user + database names per cPanel docs).
        $assignUserResult = [ 'status' => 0 ];
        if ( $dbUsername !== '' ) {
            $assignUserResult = $this->cpanelExecute(
                'Mysql/set_privileges_on_database',
                [
                    'user'       => $dbUsername,
                    'database'   => $fullDbName,
                    'privileges' => 'ALL',
                ]
            );

            // Retry with short names if the account rejects prefixed values.
            if ( ! $this->isCpanelSuccess( $assignUserResult ) ) {
                $shortUser = str_starts_with( $dbUsername, $cpanelPrefix )
                    ? substr( $dbUsername, strlen( $cpanelPrefix ) )
                    : $dbUsername;

                $assignUserResult = $this->cpanelExecute(
                    'Mysql/set_privileges_on_database',
                    [
                        'user'       => $shortUser,
                        'database'   => $dbNameForApi,
                        'privileges' => 'ALL',
                    ]
                );
            }
        }

        return [
            'database'   => $createDbResult,
            'assignment' => $assignUserResult,
            'status'     => 1,
            'full_name'  => $fullDbName,
            'error'      => null,
        ];
    }

    /**
     * Call a cPanel UAPI execute endpoint.
     *
     * @param string $path  e.g. Mysql/create_database
     * @param array  $query Query parameters
     * @return array
     */
    private function cpanelExecute( string $path, array $query = [] ): array
    {
        $creds          = $this->credentials();
        $cpanelUser     = $creds['user'];
        $cpanelPassword = $creds['password'];
        $cpanelHost     = $creds['host'];

        $url = 'https://' . $cpanelHost . ':2083/execute/' . ltrim( $path, '/' );
        if ( $query !== [] ) {
            $url .= '?' . http_build_query( $query );
        }

        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_USERPWD, "$cpanelUser:$cpanelPassword" );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 45 );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
        $response  = curl_exec( $ch );
        $httpCode  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curlError = curl_error( $ch );
        curl_close( $ch );

        if ( $response === false || $curlError !== '' ) {
            return [
                'status'    => 0,
                'errors'    => [ 'cURL error: ' . $curlError ],
                'http_code' => $httpCode,
            ];
        }

        $decoded = json_decode( $response, true );
        if ( ! is_array( $decoded ) ) {
            return [
                'status'        => 0,
                'errors'        => [ 'Invalid JSON from cPanel' ],
                'http_code'     => $httpCode,
                'raw_response'  => $response,
            ];
        }

        $decoded['http_code'] = $httpCode;

        return $decoded;
    }

    private function isCpanelSuccess( ?array $result ): bool
    {
        if ( ! is_array( $result ) ) {
            return false;
        }

        $status = $result['status'] ?? $result['result']['status'] ?? null;

        return (int) $status === 1;
    }

    private function cpanelErrorsContain( ?array $result, array $needles ): bool
    {
        $error = strtolower( (string) $this->extractCpanelError( $result ) );
        if ( $error === '' ) {
            return false;
        }

        foreach ( $needles as $needle ) {
            if ( str_contains( $error, strtolower( (string) $needle ) ) ) {
                return true;
            }
        }

        return false;
    }

    private function extractCpanelError( ?array $result ): ?string
    {
        if ( ! is_array( $result ) ) {
            return null;
        }

        $errors = $result['errors']
            ?? $result['result']['errors']
            ?? $result['error']
            ?? null;

        if ( is_array( $errors ) ) {
            return implode( '; ', array_map( 'strval', $errors ) );
        }

        if ( is_string( $errors ) && $errors !== '' ) {
            return $errors;
        }

        return null;
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
                'environment' => config( 'app.env' ),
                'success' => true
            ];
        } catch (Exception $e) {
            return [
                'error' => $e->getMessage(),
                'environment' => config( 'app.env' ),
                'success' => false
            ];
        }
    }
}
