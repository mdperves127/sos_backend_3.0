<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Courier fraud history lookup via FraudBD public API.
 *
 * @see https://www.fraudbd.com/api-documentation
 */
class FraudCheckService
{
    /**
     * @return array{
     *     phone: string,
     *     total_orders: int|null,
     *     delivered: int|null,
     *     cancelled: int|null,
     *     success_rate: float|null,
     *     risk: string|null
     * }
     */
    public function check( string $phone ): array
    {
        $normalized = $this->normalizeBangladeshPhone( $phone );

        if ( $normalized === null ) {
            throw new RuntimeException( 'Invalid Bangladesh phone number. Use a valid 01XXXXXXXXX number.' );
        }

        $apiUrl = rtrim( (string) config( 'services.fraud.api_url', 'https://fraudbd.com' ), '/' );
        $apiKey = trim( (string) config( 'services.fraud.api_key', '' ) );

        if ( $apiKey === '' || $apiUrl === '' ) {
            throw new RuntimeException( 'Fraud check is not configured. Set FRAUD_API_URL and FRAUD_API_KEY.' );
        }

        $timeout = max( 5, (int) config( 'services.fraud.timeout', 20 ) );
        $endpoint = $apiUrl . '/api/check-courier-info';

        try {
            $response = Http::withHeaders( [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'api_key'      => $apiKey,
            ] )
                ->timeout( $timeout )
                ->post( $endpoint, [
                    'phone_number' => $normalized,
                ] );
        } catch ( \Throwable $e ) {
            Log::warning( 'Fraud check provider request failed', [
                'phone' => $normalized,
                'error' => $e->getMessage(),
            ] );

            throw new RuntimeException( 'Fraud check provider is unavailable. Please try again later.' );
        }

        if ( $response->status() === 429 ) {
            throw new RuntimeException( 'Fraud check rate limit exceeded. Please try again later.' );
        }

        if ( ! $response->successful() ) {
            Log::warning( 'Fraud check provider HTTP error', [
                'phone'  => $normalized,
                'status' => $response->status(),
            ] );

            throw new RuntimeException( 'Fraud check provider returned an error.' );
        }

        $payload = $response->json();

        if ( ! is_array( $payload ) ) {
            throw new RuntimeException( 'Fraud check provider returned an invalid response.' );
        }

        // Provider validation / soft failures.
        if ( array_key_exists( 'status', $payload ) && $payload['status'] === false ) {
            $message = (string) ( $payload['message'] ?? 'Fraud check failed.' );

            throw new RuntimeException( $message );
        }

        return $this->normalizeProviderResponse( $normalized, $payload );
    }

    /**
     * Normalize to local BD mobile format: 01XXXXXXXXX (11 digits).
     */
    public function normalizeBangladeshPhone( string $phone ): ?string
    {
        $digits = preg_replace( '/\D+/', '', $phone ) ?? '';

        if ( str_starts_with( $digits, '880' ) && strlen( $digits ) >= 13 ) {
            $digits = substr( $digits, 3 );
        }

        if ( strlen( $digits ) === 10 && str_starts_with( $digits, '1' ) ) {
            $digits = '0' . $digits;
        }

        if ( ! preg_match( '/^01[3-9]\d{8}$/', $digits ) ) {
            return null;
        }

        return $digits;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     phone: string,
     *     total_orders: int|null,
     *     delivered: int|null,
     *     cancelled: int|null,
     *     success_rate: float|null,
     *     risk: string|null
     * }
     */
    public function normalizeProviderResponse( string $phone, array $payload ): array
    {
        $data = is_array( $payload['data'] ?? null ) ? $payload['data'] : $payload;
        $summary = is_array( $data['totalSummary'] ?? null ) ? $data['totalSummary'] : [];

        $total = $this->nullableInt(
            $summary['total']
                ?? $data['total']
                ?? $data['total_orders']
                ?? null
        );
        $delivered = $this->nullableInt(
            $summary['success']
                ?? $data['success']
                ?? $data['delivered']
                ?? null
        );
        $cancelled = $this->nullableInt(
            $summary['cancel']
                ?? $data['cancel']
                ?? $data['cancelled']
                ?? null
        );

        $successRate = null;
        if ( array_key_exists( 'successRate', $summary ) && $summary['successRate'] !== null && $summary['successRate'] !== '' ) {
            $successRate = round( (float) $summary['successRate'], 2 );
        } elseif ( array_key_exists( 'success_rate', $data ) && $data['success_rate'] !== null && $data['success_rate'] !== '' ) {
            $successRate = round( (float) $data['success_rate'], 2 );
        } elseif ( $total !== null && $total > 0 && $delivered !== null ) {
            $successRate = round( ( $delivered / $total ) * 100, 2 );
        }

        $risk = null;
        if ( is_string( $data['risk'] ?? null ) && $data['risk'] !== '' ) {
            $risk = strtolower( (string) $data['risk'] );
        } elseif ( is_string( $summary['risk_level'] ?? null ) && $summary['risk_level'] !== '' ) {
            $risk = strtolower( (string) $summary['risk_level'] );
        } elseif ( ( $total ?? 0 ) === 0 && $total !== null ) {
            $risk = 'unknown';
            $successRate = null;
        } elseif ( $successRate !== null ) {
            $risk = $this->riskFromSuccessRate( $successRate );
        } else {
            $risk = 'unknown';
        }

        if ( $total === 0 ) {
            return [
                'phone'         => $phone,
                'total_orders'  => 0,
                'delivered'     => $delivered ?? 0,
                'cancelled'     => $cancelled ?? 0,
                'success_rate'  => null,
                'risk'          => 'unknown',
            ];
        }

        return [
            'phone'         => $phone,
            'total_orders'  => $total,
            'delivered'     => $delivered,
            'cancelled'     => $cancelled,
            'success_rate'  => $successRate,
            'risk'          => $risk,
        ];
    }

    private function riskFromSuccessRate( float $rate ): string
    {
        if ( $rate >= 80 ) {
            return 'low';
        }
        if ( $rate >= 50 ) {
            return 'medium';
        }

        return 'high';
    }

    private function nullableInt( mixed $value ): ?int
    {
        if ( $value === null || $value === '' ) {
            return null;
        }

        if ( ! is_numeric( $value ) ) {
            return null;
        }

        return (int) $value;
    }
}
