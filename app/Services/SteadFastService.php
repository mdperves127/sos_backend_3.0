<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Steadfast Courier (Packzy) API v1 client.
 *
 * @see https://docs.google.com/document/d/e/2PACX-1vTi0sTyR353xu1AK0nR8E_WKe5onCkUXGEf8ch8uoJy9qxGfgGnboSIkNosjQ0OOdXkJhgGuAsWxnIh/pub
 */
class SteadFastService
{
    public static function baseUrl(): string
    {
        return rtrim( (string) config( 'services.steadfast.base_url', 'https://portal.packzy.com/api/v1' ), '/' );
    }

    /**
     * Backward-compatible alias for createOrder().
     *
     * @param  array|object  $order
     * @return array
     */
    public static function order( $apiKey, $secretKey, $order ): array
    {
        return self::createOrder( $apiKey, $secretKey, $order );
    }

    /**
     * POST /create_order
     *
     * @param  array|object  $order
     */
    public static function createOrder( $apiKey, $secretKey, $order ): array
    {
        $payload = self::buildOrderPayload( $order );
        $errors  = self::validateOrderPayload( $payload );

        if ( $errors !== [] ) {
            return [
                'message' => 'Invalid Steadfast order payload.',
                'status'  => 422,
                'details' => ['errors' => $errors],
            ];
        }

        return self::request( $apiKey, $secretKey, 'post', '/create_order', $payload );
    }

    /**
     * POST /create_order/bulk-order
     * Max 500 items. $orders is a list of order arrays/models.
     *
     * @param  array<int, array|object>  $orders
     */
    public static function bulkCreate( $apiKey, $secretKey, array $orders ): array
    {
        if ( count( $orders ) > 500 ) {
            return [
                'message' => 'Steadfast bulk create allows maximum 500 items.',
                'status'  => 422,
            ];
        }

        $data = [];
        foreach ( $orders as $order ) {
            $payload = self::buildOrderPayload( $order );
            $errors  = self::validateOrderPayload( $payload );
            if ( $errors !== [] ) {
                return [
                    'message' => 'Invalid Steadfast bulk item.',
                    'status'  => 422,
                    'details' => [
                        'invoice' => $payload['invoice'] ?? null,
                        'errors'  => $errors,
                    ],
                ];
            }
            $data[] = $payload;
        }

        // Docs send JSON-encoded array in `data`.
        return self::request( $apiKey, $secretKey, 'post', '/create_order/bulk-order', [
            'data' => json_encode( $data ),
        ] );
    }

    /** GET /status_by_cid/{id} */
    public static function statusByConsignmentId( $apiKey, $secretKey, $consignmentId ): array
    {
        return self::request( $apiKey, $secretKey, 'get', '/status_by_cid/' . rawurlencode( (string) $consignmentId ) );
    }

    /** GET /status_by_invoice/{invoice} */
    public static function statusByInvoice( $apiKey, $secretKey, string $invoice ): array
    {
        return self::request( $apiKey, $secretKey, 'get', '/status_by_invoice/' . rawurlencode( $invoice ) );
    }

    /** GET /status_by_trackingcode/{trackingCode} */
    public static function statusByTrackingCode( $apiKey, $secretKey, string $trackingCode ): array
    {
        return self::request( $apiKey, $secretKey, 'get', '/status_by_trackingcode/' . rawurlencode( $trackingCode ) );
    }

    /**
     * Resolve delivery status by whichever identifier is provided.
     */
    public static function getDeliveryStatus( $apiKey, $secretKey, ?string $consignmentId = null, ?string $invoice = null, ?string $trackingCode = null ): array
    {
        if ( $consignmentId ) {
            return self::statusByConsignmentId( $apiKey, $secretKey, $consignmentId );
        }
        if ( $invoice ) {
            return self::statusByInvoice( $apiKey, $secretKey, $invoice );
        }
        if ( $trackingCode ) {
            return self::statusByTrackingCode( $apiKey, $secretKey, $trackingCode );
        }

        return [
            'message' => 'Provide consignment_id, invoice, or tracking_code.',
            'status'  => 422,
        ];
    }

    /** GET /get_balance */
    public static function getBalance( $apiKey, $secretKey ): array
    {
        return self::request( $apiKey, $secretKey, 'get', '/get_balance' );
    }

    /**
     * POST /create_return_request
     * Body must include one of: consignment_id | invoice | tracking_code
     */
    public static function createReturnRequest( $apiKey, $secretKey, array $payload ): array
    {
        $hasId = ! empty( $payload['consignment_id'] )
            || ! empty( $payload['invoice'] )
            || ! empty( $payload['tracking_code'] );

        if ( ! $hasId ) {
            return [
                'message' => 'consignment_id, invoice, or tracking_code is required.',
                'status'  => 422,
            ];
        }

        $body = array_filter( [
            'consignment_id' => $payload['consignment_id'] ?? null,
            'invoice'        => $payload['invoice'] ?? null,
            'tracking_code'  => $payload['tracking_code'] ?? null,
            'reason'         => $payload['reason'] ?? null,
        ], static fn ( $v ) => $v !== null && $v !== '' );

        return self::request( $apiKey, $secretKey, 'post', '/create_return_request', $body );
    }

    /** GET /get_return_request/{id} */
    public static function getReturnRequest( $apiKey, $secretKey, $id ): array
    {
        return self::request( $apiKey, $secretKey, 'get', '/get_return_request/' . rawurlencode( (string) $id ) );
    }

    /** GET /get_return_requests */
    public static function getReturnRequests( $apiKey, $secretKey ): array
    {
        return self::request( $apiKey, $secretKey, 'get', '/get_return_requests' );
    }

    /** GET /payments */
    public static function getPayments( $apiKey, $secretKey ): array
    {
        return self::request( $apiKey, $secretKey, 'get', '/payments' );
    }

    /** GET /payments/{payment_id} */
    public static function getPayment( $apiKey, $secretKey, $paymentId ): array
    {
        return self::request( $apiKey, $secretKey, 'get', '/payments/' . rawurlencode( (string) $paymentId ) );
    }

    /** GET /police_stations */
    public static function getPoliceStations( $apiKey, $secretKey ): array
    {
        return self::request( $apiKey, $secretKey, 'get', '/police_stations' );
    }

    public static function isCreateSuccess( array $response ): bool
    {
        return (int) ( $response['status'] ?? 0 ) === 200
            && isset( $response['consignment']['consignment_id'] );
    }

    /**
     * @param  array|object  $order
     */
    public static function buildOrderPayload( $order ): array
    {
        $invoice = self::sanitizeInvoice( (string) self::value( $order, 'merchant_order_id', self::value( $order, 'invoice', '' ) ) );
        $phone   = self::normalizeBdPhone( (string) self::value( $order, 'recipient_phone', '' ) );
        $alt     = self::normalizeBdPhone( (string) self::value( $order, 'alternative_phone', '' ) );

        $name    = mb_substr( trim( (string) self::value( $order, 'recipient_name', '' ) ), 0, 100 );
        $address = mb_substr( trim( (string) self::value( $order, 'recipient_address', '' ) ), 0, 250 );
        $cod     = max( 0, (float) self::value( $order, 'amount_to_collect', self::value( $order, 'cod_amount', 0 ) ) );

        // Steadfast: 0 = home, 1 = hub. Do not pass Pathao-style delivery_type (e.g. 48).
        $rawDeliveryType = self::value( $order, 'steadfast_delivery_type', self::value( $order, 'delivery_type', 0 ) );
        $deliveryType    = in_array( (int) $rawDeliveryType, [0, 1], true ) ? (int) $rawDeliveryType : 0;

        $payload = [
            'invoice'           => $invoice,
            'recipient_name'    => $name,
            'recipient_phone'   => $phone,
            'recipient_address' => $address,
            'cod_amount'        => $cod,
            'delivery_type'     => $deliveryType,
        ];

        $note = trim( (string) self::value( $order, 'special_instruction', self::value( $order, 'note', '' ) ) );
        if ( $note !== '' ) {
            $payload['note'] = $note;
        }

        $itemDescription = trim( (string) self::value( $order, 'item_description', '' ) );
        if ( $itemDescription !== '' ) {
            $payload['item_description'] = $itemDescription;
        }

        $email = trim( (string) self::value( $order, 'recipient_email', '' ) );
        if ( $email !== '' ) {
            $payload['recipient_email'] = $email;
        }

        if ( $alt !== '' ) {
            $payload['alternative_phone'] = $alt;
        }

        $totalLot = self::value( $order, 'total_lot', self::value( $order, 'item_quantity', null ) );
        if ( $totalLot !== null && $totalLot !== '' ) {
            $payload['total_lot'] = max( 1, (int) $totalLot );
        }

        return $payload;
    }

    public static function validateOrderPayload( array $payload ): array
    {
        $errors = [];

        if ( ( $payload['invoice'] ?? '' ) === '' ) {
            $errors['invoice'] = 'Invoice is required.';
        } elseif ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $payload['invoice'] ) ) {
            $errors['invoice'] = 'Invoice must be alphanumeric (hyphen/underscore allowed).';
        }

        if ( ( $payload['recipient_name'] ?? '' ) === '' ) {
            $errors['recipient_name'] = 'Recipient name is required.';
        }

        if ( ! preg_match( '/^01\d{9}$/', (string) ( $payload['recipient_phone'] ?? '' ) ) ) {
            $errors['recipient_phone'] = 'Recipient phone must be an 11-digit BD number (01XXXXXXXXX).';
        }

        if ( isset( $payload['alternative_phone'] ) && $payload['alternative_phone'] !== ''
            && ! preg_match( '/^01\d{9}$/', (string) $payload['alternative_phone'] ) ) {
            $errors['alternative_phone'] = 'Alternative phone must be an 11-digit BD number (01XXXXXXXXX).';
        }

        if ( ( $payload['recipient_address'] ?? '' ) === '' ) {
            $errors['recipient_address'] = 'Recipient address is required.';
        }

        if ( ! isset( $payload['cod_amount'] ) || (float) $payload['cod_amount'] < 0 ) {
            $errors['cod_amount'] = 'COD amount cannot be less than 0.';
        }

        return $errors;
    }

    public static function normalizeBdPhone( string $phone ): string
    {
        $digits = preg_replace( '/\D+/', '', $phone ) ?? '';

        if ( $digits === '' ) {
            return '';
        }

        // 8801XXXXXXXXX → 01XXXXXXXXX
        if ( str_starts_with( $digits, '880' ) && strlen( $digits ) >= 13 ) {
            $digits = substr( $digits, -11 );
        }

        // 1XXXXXXXXX (10 digits missing leading 0)
        if ( strlen( $digits ) === 10 && str_starts_with( $digits, '1' ) ) {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    public static function sanitizeInvoice( string $invoice ): string
    {
        $invoice = trim( $invoice );
        $invoice = preg_replace( '/[^A-Za-z0-9_-]+/', '_', $invoice ) ?? '';
        $invoice = trim( $invoice, '_' );

        return $invoice;
    }

    private static function request( $apiKey, $secretKey, string $method, string $path, array $body = [] ): array
    {
        try {
            $http = Http::withHeaders( [
                'Api-Key'      => (string) $apiKey,
                'Secret-Key'   => (string) $secretKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ] )->timeout( 60 );

            $url = self::baseUrl() . $path;

            $response = strtolower( $method ) === 'get'
                ? $http->get( $url, $body )
                : $http->post( $url, $body );

            $json = $response->json();
            if ( ! is_array( $json ) ) {
                $json = ['raw' => $response->body()];
            }

            if ( $response->failed() ) {
                Log::warning( 'Steadfast API request failed', [
                    'path'   => $path,
                    'status' => $response->status(),
                    'body'   => $json,
                ] );

                return [
                    'message' => self::extractErrorMessage( $json, 'Steadfast API request failed' ),
                    'status'  => $response->status(),
                    'details' => $json,
                ];
            }

            // Normalize successful JSON; keep HTTP status if API omitted status.
            if ( ! isset( $json['status'] ) ) {
                $json['status'] = $response->status();
            }

            return $json;
        } catch ( \Throwable $e ) {
            Log::error( 'Steadfast API exception', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ] );

            return [
                'message' => $e->getMessage(),
                'status'  => 500,
            ];
        }
    }

    private static function extractErrorMessage( array $json, string $fallback ): string
    {
        foreach ( ['message', 'error', 'errors'] as $key ) {
            if ( ! isset( $json[$key] ) ) {
                continue;
            }
            if ( is_string( $json[$key] ) && trim( $json[$key] ) !== '' ) {
                return $json[$key];
            }
            if ( is_array( $json[$key] ) ) {
                $encoded = json_encode( $json[$key] );
                if ( is_string( $encoded ) && $encoded !== '[]' && $encoded !== '{}' ) {
                    return $encoded;
                }
            }
        }

        return $fallback;
    }

    /**
     * @param  array|object  $source
     */
    private static function value( $source, string $key, $default = null )
    {
        if ( is_array( $source ) ) {
            return $source[$key] ?? $default;
        }

        if ( is_object( $source ) ) {
            return $source->{$key} ?? $default;
        }

        return $default;
    }
}
