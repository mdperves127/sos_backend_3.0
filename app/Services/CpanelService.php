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
     * @return array{user: string, password: string, host: string, main_domain: string, api_token: string, port: int}
     */
    private function credentials(): array
    {
        return [
            'user'        => trim( (string) config( 'cpanel.user', '' ) ),
            'password'    => (string) config( 'cpanel.password', '' ),
            'api_token'   => trim( (string) config( 'cpanel.api_token', '' ) ),
            'host'        => trim( (string) config( 'cpanel.host', '127.0.0.1' ) ),
            'port'        => (int) config( 'cpanel.port', 2083 ),
            'main_domain' => trim( (string) config( 'cpanel.main_domain', '' ) ),
        ];
    }

    /**
     * Create subdomain using cPanel UAPI (same auth/host fallback as DB create).
     *
     * @param string $subdomain Bare subdomain label (e.g. "myshop"), not the FQDN
     * @return array
     */
    private function createSubdomainViaCpanel($subdomain)
    {
        try {
            $creds      = $this->credentials();
            $cpanelUser = $creds['user'];
            $cpanelHost = $creds['host'];
            $mainDomain = $creds['main_domain'];
            $apiToken   = $creds['api_token'];
            $password   = $creds['password'];

            if ( $cpanelUser === '' || $cpanelHost === '' || $mainDomain === '' || ( $password === '' && $apiToken === '' ) ) {
                \Log::error( 'cPanel subdomain creation: Missing required configuration', [
                    'has_user'        => $cpanelUser !== '',
                    'has_password'    => $password !== '',
                    'has_token'       => $apiToken !== '',
                    'has_host'        => $cpanelHost !== '',
                    'has_main_domain' => $mainDomain !== '',
                ] );

                return [
                    'status'  => 0,
                    'error'   => 'Missing cPanel configuration',
                    'message' => 'Set CPANEL_USER, CPANEL_HOST, MAIN_DOMAIN, and CPANEL_PASSWORD (or CPANEL_API_TOKEN). Prefer CPANEL_HOST=127.0.0.1 on the same server.',
                ];
            }

            $subdomain = strtolower( trim( (string) $subdomain ) );
            // If a full domain was passed, keep only the leftmost label.
            if ( str_contains( $subdomain, '.' ) ) {
                $subdomain = explode( '.', $subdomain )[0];
            }

            $subdomainDir = (string) config( 'cpanel.tenant_root', 'public_html/' );

            \Log::info( 'cPanel subdomain creation: Making API call', [
                'subdomain'   => $subdomain,
                'main_domain' => $mainDomain,
                'dir'         => $subdomainDir,
                'host'        => $cpanelHost,
            ] );

            $result = $this->cpanelExecute( 'SubDomain/addsubdomain', [
                'domain'     => $subdomain,
                'rootdomain' => $mainDomain,
                'dir'        => $subdomainDir,
            ] );

            $success       = $this->isCpanelSuccess( $result );
            $alreadyExists = $this->cpanelErrorsContain( $result, [ 'exists', 'already', 'in use' ] );

            \Log::info( 'cPanel subdomain creation: API response', [
                'subdomain'      => $subdomain,
                'success'        => $success,
                'already_exists' => $alreadyExists,
                'result'         => $result,
            ] );

            if ( $success || $alreadyExists ) {
                $phpVersion       = (string) config( 'cpanel.php_version', 'ea-php82' );
                $phpVersionResult = $this->setPhpVersionForSubdomain( $subdomain, $mainDomain, $phpVersion );

                \Log::info( 'cPanel subdomain creation: PHP version setting result', [
                    'subdomain'          => $subdomain,
                    'php_version_result' => $phpVersionResult,
                ] );

                return [
                    'status'              => 1,
                    'message'             => $alreadyExists
                        ? 'Subdomain already exists'
                        : 'Subdomain created successfully',
                    'subdomain'           => $subdomain,
                    'full_domain'         => $subdomain . '.' . $mainDomain,
                    'php_version_set'     => $phpVersionResult['status'] ?? 0,
                    'php_version_message' => $phpVersionResult['message'] ?? ( $phpVersionResult['error'] ?? '' ),
                    'full_response'       => $result,
                ];
            }

            $errorMessage = $this->extractCpanelError( $result ) ?: 'Unknown error from cPanel API';

            \Log::error( 'cPanel subdomain creation: Failed', [
                'subdomain' => $subdomain,
                'error'     => $errorMessage,
                'response'  => $result,
            ] );

            return [
                'status'        => 0,
                'error'         => $errorMessage,
                'full_response' => $result,
            ];
        } catch ( \Exception $e ) {
            \Log::error( 'cPanel subdomain creation: Exception', [
                'subdomain' => $subdomain,
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ] );

            return [
                'status' => 0,
                'error'  => 'Exception: ' . $e->getMessage(),
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
    private function setPhpVersionForSubdomain( $subdomain, $mainDomain, $phpVersion = 'ea-php82' )
    {
        try {
            $fullDomain = $subdomain . '.' . $mainDomain;

            if ( preg_match( '/^\d{2}$/', $phpVersion ) ) {
                $phpVersion = 'ea-php' . $phpVersion;
            }

            \Log::info( 'cPanel PHP version setting: Making API call', [
                'vhost'       => $fullDomain,
                'php_version' => $phpVersion,
            ] );

            $result = $this->cpanelExecute( 'LangPHP/php_set_vhost_versions', [
                'vhost'   => $fullDomain,
                'version' => $phpVersion,
            ] );

            \Log::info( 'cPanel PHP version setting: API response', [
                'vhost'       => $fullDomain,
                'php_version' => $phpVersion,
                'response'    => $result,
            ] );

            if ( $this->isCpanelSuccess( $result ) ) {
                return [
                    'status'        => 1,
                    'message'       => 'PHP version set to ' . $phpVersion . ' successfully',
                    'domain'        => $fullDomain,
                    'php_version'   => $phpVersion,
                    'full_response' => $result,
                ];
            }

            $errorMessage = $this->extractCpanelError( $result ) ?: 'Unexpected response setting PHP version';

            \Log::error( 'cPanel PHP version setting: API error', [
                'vhost'       => $fullDomain,
                'php_version' => $phpVersion,
                'error'       => $errorMessage,
            ] );

            return [
                'status'        => 0,
                'error'         => $errorMessage,
                'full_response' => $result,
            ];
        } catch ( \Exception $e ) {
            \Log::error( 'cPanel PHP version setting: Exception', [
                'domain'      => $subdomain . '.' . $mainDomain,
                'php_version' => $phpVersion,
                'exception'   => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ] );

            return [
                'status' => 0,
                'error'  => 'Exception: ' . $e->getMessage(),
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
        $apiToken       = $creds['api_token'];

        if ( $cpanelUser === '' || $cpanelHost === '' || ( $cpanelPassword === '' && $apiToken === '' ) ) {
            \Log::error( 'cPanel database creation: Missing required environment variables', [
                'has_user'     => $cpanelUser !== '',
                'has_password' => $cpanelPassword !== '',
                'has_token'    => $apiToken !== '',
                'has_host'     => $cpanelHost !== '',
            ] );

            return [
                'database'   => ['status' => 0, 'errors' => ['Missing cPanel configuration']],
                'assignment' => ['status' => 0],
                'status'     => 0,
                'full_name'  => (string) $dbname,
                'error'      => 'Missing cPanel configuration. Set CPANEL_USER, CPANEL_HOST, and CPANEL_PASSWORD (or CPANEL_API_TOKEN). Prefer CPANEL_HOST=127.0.0.1 on the same server.',
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

        // Step 1: Create the database.
        // Some cPanel hosts auto-prefix (send short name); others require the full prefixed name.
        $createDbResult = $this->cpanelExecute(
            'Mysql/create_database',
            [ 'name' => $dbNameForApi ]
        );

        $createOk      = $this->isCpanelSuccess( $createDbResult );
        $alreadyExists = $this->cpanelErrorsContain( $createDbResult, [ 'exists', 'already' ] );
        $needsPrefix   = $this->cpanelErrorsContain( $createDbResult, [ 'required prefix', 'does not begin with', 'begin with the required' ] );

        if ( ! $createOk && ! $alreadyExists && $needsPrefix ) {
            \Log::info( 'cPanel create_database requires prefixed name; retrying', [
                'short_name' => $dbNameForApi,
                'full_name'  => $fullDbName,
            ] );

            $createDbResult = $this->cpanelExecute(
                'Mysql/create_database',
                [ 'name' => $fullDbName ]
            );

            $createOk      = $this->isCpanelSuccess( $createDbResult );
            $alreadyExists = $this->cpanelErrorsContain( $createDbResult, [ 'exists', 'already' ] );
        }

        // Prefer the full name returned by cPanel when present.
        $returnedName = $createDbResult['data']['name']
            ?? $createDbResult['result']['data']['name']
            ?? null;
        if ( is_string( $returnedName ) && $returnedName !== '' ) {
            $fullDbName = $returnedName;
        }

        \Log::info( 'cPanel database creation response', [
            'short_name'     => $dbNameForApi,
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
     * Tries the configured host first, then 127.0.0.1 / localhost when the
     * public hostname returns HTML (Cloudflare, login page, etc.).
     *
     * @param string $path  e.g. Mysql/create_database
     * @param array  $query Query parameters
     * @return array
     */
    private function cpanelExecute( string $path, array $query = [] ): array
    {
        $creds = $this->credentials();
        $hosts = array_values( array_unique( array_filter( [
            $creds['host'],
            '127.0.0.1',
            'localhost',
        ] ) ) );

        $lastFailure = [
            'status' => 0,
            'errors' => [ 'cPanel request failed' ],
        ];

        foreach ( $hosts as $host ) {
            $result = $this->cpanelExecuteAgainstHost( $host, $path, $query );

            if ( $this->isUsableCpanelPayload( $result ) ) {
                if ( $host !== $creds['host'] ) {
                    \Log::info( 'cPanel API succeeded via fallback host', [
                        'configured_host' => $creds['host'],
                        'used_host'       => $host,
                        'path'            => $path,
                    ] );
                }

                return $result;
            }

            $lastFailure = $result;
        }

        return $lastFailure;
    }

    /**
     * @return array
     */
    private function cpanelExecuteAgainstHost( string $host, string $path, array $query = [] ): array
    {
        $creds          = $this->credentials();
        $cpanelUser     = $creds['user'];
        $cpanelPassword = $creds['password'];
        $apiToken       = $creds['api_token'];
        $port           = $creds['port'] ?: 2083;

        if ( $cpanelUser === '' || ( $cpanelPassword === '' && $apiToken === '' ) ) {
            return [
                'status' => 0,
                'errors' => [ 'Missing cPanel user/password (or CPANEL_API_TOKEN)' ],
            ];
        }

        $url = 'https://' . $host . ':' . $port . '/execute/' . ltrim( $path, '/' );
        if ( $query !== [] ) {
            $url .= '?' . http_build_query( $query );
        }

        // Prefer API token auth; otherwise Basic auth (handles special chars in password).
        if ( $apiToken !== '' ) {
            $authHeader = 'Authorization: cpanel ' . $cpanelUser . ':' . $apiToken;
        } else {
            $authHeader = 'Authorization: Basic ' . base64_encode( $cpanelUser . ':' . $cpanelPassword );
        }

        $ch = curl_init();
        curl_setopt_array( $ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                $authHeader,
                'Accept: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_ENCODING       => '',
        ] );

        $response  = curl_exec( $ch );
        $httpCode  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curlError = curl_error( $ch );
        curl_close( $ch );

        if ( $response === false || $curlError !== '' ) {
            return [
                'status'    => 0,
                'errors'    => [ 'cURL error (' . $host . '): ' . $curlError ],
                'http_code' => $httpCode,
                'host'      => $host,
            ];
        }

        $decoded = json_decode( $response, true );
        if ( ! is_array( $decoded ) ) {
            $summary = $this->summarizeNonJsonResponse( (string) $response, $httpCode, $host );

            \Log::error( 'cPanel non-JSON response', [
                'host'      => $host,
                'path'      => $path,
                'http_code' => $httpCode,
                'summary'   => $summary,
                'preview'   => mb_substr( preg_replace( '/\s+/', ' ', (string) $response ), 0, 300 ),
            ] );

            return [
                'status'       => 0,
                'errors'       => [ $summary ],
                'http_code'    => $httpCode,
                'host'         => $host,
                'raw_response' => mb_substr( (string) $response, 0, 500 ),
            ];
        }

        $decoded['http_code'] = $httpCode;
        $decoded['host']      = $host;

        return $decoded;
    }

    private function isUsableCpanelPayload( array $result ): bool
    {
        // Valid JSON from UAPI always has status (0 or 1) or result.status
        if ( array_key_exists( 'status', $result ) || isset( $result['result']['status'] ) ) {
            // Exclude our own synthetic failures that still use status=0 + errors only
            if ( isset( $result['errors'][0] ) && is_string( $result['errors'][0] ) ) {
                $first = $result['errors'][0];
                if ( str_starts_with( $first, 'cURL error' )
                    || str_contains( $first, 'non-JSON' )
                    || str_contains( $first, 'Invalid JSON' )
                    || str_contains( $first, 'HTML instead of JSON' )
                    || str_contains( $first, 'Missing cPanel' )
                ) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function summarizeNonJsonResponse( string $response, int $httpCode, string $host ): string
    {
        $trimmed = trim( $response );

        if ( $trimmed === '' ) {
            return "cPanel returned empty body (HTTP {$httpCode}) from {$host}:{$this->credentials()['port']}. Try CPANEL_HOST=127.0.0.1";
        }

        if ( str_contains( strtolower( $trimmed ), '<html' )
            || str_contains( strtolower( $trimmed ), '<!doctype' )
        ) {
            return "cPanel returned HTML instead of JSON (HTTP {$httpCode}) from {$host}. Set CPANEL_HOST=127.0.0.1 in .env (same server) or use CPANEL_API_TOKEN, then run php artisan config:cache";
        }

        return 'cPanel non-JSON response (HTTP ' . $httpCode . ') from ' . $host . ': ' . mb_substr( preg_replace( '/\s+/', ' ', $trimmed ), 0, 180 );
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
