<?php

namespace App\Services;

use App\Models\WoocommerceCredential;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Thin WooCommerce REST client bound to a tenant credential row.
 */
class WooCommerceService {
    protected ?WoocommerceCredential $credential = null;

    protected Client $client;

    public function __construct( ?Client $client = null ) {
        $this->client = $client ?: new Client( [
            'timeout'         => 30,
            'connect_timeout' => 10,
            'http_errors'     => false,
        ] );
    }

    public function forCredential( WoocommerceCredential $credential ): self {
        $clone             = clone $this;
        $clone->credential = $credential;

        return $clone;
    }

    public function activeCredential( ?int $vendorId = null ): ?WoocommerceCredential {
        $vendorId = $vendorId ?? ( function_exists( 'vendorId' ) ? vendorId() : null );

        return WoocommerceCredential::query()
            ->when( $vendorId, fn ( $q ) => $q->where( 'vendor_id', $vendorId ) )
            ->where( 'is_active', true )
            ->latest( 'id' )
            ->first()
            ?: WoocommerceCredential::query()
                ->when( $vendorId, fn ( $q ) => $q->where( 'vendor_id', $vendorId ) )
                ->latest( 'id' )
                ->first();
    }

    /** @deprecated use activeCredential() */
    public function wcCredential() {
        return $this->activeCredential();
    }

    public function validateConnection( string $url, string $key, string $secret ): array {
        $url = self::assertSafeStoreUrl( $url );

        try {
            $response = $this->client->request( 'GET', $url . '/wp-json/wc/v3/system_status', [
                'auth' => [$key, $secret],
            ] );

            $code = $response->getStatusCode();
            if ( $code === 401 || $code === 403 ) {
                // Some stores restrict system_status; fall back to products.
                $response = $this->client->request( 'GET', $url . '/wp-json/wc/v3/products', [
                    'auth'  => [$key, $secret],
                    'query' => ['per_page' => 1],
                ] );
                $code = $response->getStatusCode();
            }

            if ( ! in_array( $code, [200, 201], true ) ) {
                throw new RuntimeException( 'WooCommerce API returned HTTP ' . $code );
            }

            return [
                'ok'      => true,
                'message' => 'Connection successful.',
                'status'  => $code,
            ];
        } catch ( GuzzleException $e ) {
            throw new RuntimeException( 'Unable to reach WooCommerce store: ' . $e->getMessage(), 0, $e );
        }
    }

    public function request( string $method, string $path, array $options = [] ): array {
        $credential = $this->credential ?: $this->activeCredential();
        if ( ! $credential ) {
            throw new RuntimeException( 'No WooCommerce credentials configured.' );
        }

        $url = $credential->normalizedUrl() . '/wp-json/wc/v3/' . ltrim( $path, '/' );

        try {
            $response = $this->client->request( $method, $url, array_merge( [
                'auth' => [$credential->wc_key, $credential->wc_secret],
            ], $options ) );
        } catch ( GuzzleException $e ) {
            throw new RuntimeException( 'WooCommerce request failed: ' . $e->getMessage(), 0, $e );
        }

        $code = $response->getStatusCode();
        $body = json_decode( (string) $response->getBody(), true );

        if ( $code === 429 ) {
            throw new RuntimeException( 'WooCommerce rate limit hit. Retry later.' );
        }

        if ( $code < 200 || $code >= 300 ) {
            $message = is_array( $body ) ? ( $body['message'] ?? json_encode( $body ) ) : (string) $response->getBody();
            throw new RuntimeException( 'WooCommerce API error (' . $code . '): ' . $message );
        }

        return [
            'status'  => $code,
            'data'    => $body,
            'headers' => [
                'total'       => $response->getHeaderLine( 'X-WP-Total' ),
                'total_pages' => $response->getHeaderLine( 'X-WP-TotalPages' ),
            ],
        ];
    }

    public function get( string $path, array $query = [] ): array {
        return $this->request( 'GET', $path, ['query' => $query] );
    }

    public function post( string $path, array $json ): array {
        return $this->request( 'POST', $path, ['json' => $json] );
    }

    public function put( string $path, array $json ): array {
        return $this->request( 'PUT', $path, ['json' => $json] );
    }

    public function delete( string $path, array $query = [] ): array {
        return $this->request( 'DELETE', $path, ['query' => $query] );
    }

    /** @return array<int, array> */
    public function paginateAll( string $path, array $query = [], int $perPage = 50, int $maxPages = 50 ): array {
        $page    = 1;
        $results = [];

        do {
            $response = $this->get( $path, array_merge( $query, [
                'per_page' => $perPage,
                'page'     => $page,
            ] ) );

            $chunk = is_array( $response['data'] ) ? $response['data'] : [];
            $results = array_merge( $results, $chunk );

            $totalPages = (int) ( $response['headers']['total_pages'] ?: 1 );
            $page++;
        } while ( $page <= $totalPages && $page <= $maxPages && $chunk !== [] );

        return $results;
    }

    public static function verifyWebhookSignature( string $payload, ?string $signature, ?string $secret ): bool {
        if ( ! $secret ) {
            // No secret configured — allow but caller should log.
            return true;
        }
        if ( ! $signature ) {
            return false;
        }

        $expected = base64_encode( hash_hmac( 'sha256', $payload, $secret, true ) );

        return hash_equals( $expected, $signature );
    }

    /**
     * Normalize and reject SSRF-prone store URLs (private hosts, non-http(s), etc.).
     */
    public static function assertSafeStoreUrl( string $url ): string {
        $url = rtrim( trim( $url ), '/' );
        $parts = parse_url( $url );

        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            throw new RuntimeException( 'Invalid store URL.' );
        }

        $scheme = strtolower( (string) $parts['scheme'] );
        if ( ! in_array( $scheme, ['https', 'http'], true ) ) {
            throw new RuntimeException( 'Store URL must use http or https.' );
        }

        // Production stores should be HTTPS; allow http only for local/dev hosts.
        $host = strtolower( (string) $parts['host'] );
        $isLocalHost = in_array( $host, ['localhost', '127.0.0.1', '::1'], true )
            || str_ends_with( $host, '.local' )
            || str_ends_with( $host, '.test' );

        if ( $scheme !== 'https' && ! $isLocalHost ) {
            throw new RuntimeException( 'Store URL must use HTTPS.' );
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) && ! $isLocalHost ) {
                throw new RuntimeException( 'Store URL host is not allowed.' );
            }
        }

        return $url;
    }
}
