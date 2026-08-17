<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handle RedX merchant webhooks and sync order status.
 *
 * Configure in RedX dashboard (Account > Developer API > Open API > Webhook):
 *   https://{API_HOST}/api/public/redx/webhook/{tenant_id}?token={REDX_WEBHOOK_SECRET}
 *
 * Statuses of interest:
 * - delivered / partial-delivery → delivered
 * - returned → return
 *
 * @see https://redx.com.bd/developer-api/
 */
class RedxWebhookService
{
    public const DELIVERED_STATUSES = [
        'delivered',
        'partial-delivery',
        'partial_delivery',
        'partial-delivered',
        'partial_delivered',
    ];

    public const RETURN_STATUSES = [
        'returned',
        'return',
        'agent-returning',
        'agent_returning',
        'partial-return',
        'partial_return',
        'exchange-return',
        'exchange_return',
    ];

    public static function webhookSecret(): string
    {
        return (string) config( 'services.redx.webhook_secret', env( 'REDX_WEBHOOK_SECRET', '' ) );
    }

    public static function mapStatusToOrderStatus( ?string $status, ?string $deliveryType = null ): ?string
    {
        $status = strtolower( trim( (string) $status ) );
        $deliveryType = strtolower( trim( (string) $deliveryType ) );

        if ( in_array( $status, self::DELIVERED_STATUSES, true )
            || in_array( $deliveryType, ['partial-delivery', 'partial_delivery'], true ) && $status === 'delivered' ) {
            return 'delivered';
        }

        if ( in_array( $status, self::RETURN_STATUSES, true )
            || in_array( $deliveryType, ['partial-return', 'partial_return', 'exchange-return', 'exchange_return', 'reverse'], true ) ) {
            // Only treat reverse/partial-return types as return when the parcel is actually returned.
            if ( in_array( $status, self::RETURN_STATUSES, true )
                || in_array( $deliveryType, ['partial-return', 'partial_return', 'exchange-return', 'exchange_return'], true ) ) {
                return 'return';
            }
        }

        return null;
    }

    /**
     * @return array{ok:bool,http:int,message:string,action?:string|null,order_id?:int|null}
     */
    public static function handle( array $payload, ?string $tenantId = null ): array
    {
        $status        = self::stringOrNull( $payload['status'] ?? $payload['parcel_status'] ?? null );
        $deliveryType  = self::stringOrNull( $payload['delivery_type'] ?? null );
        $trackingNumber = self::stringOrNull(
            $payload['tracking_number']
            ?? $payload['tracking_id']
            ?? $payload['trackingId']
            ?? null
        );
        $invoice = self::stringOrNull(
            $payload['invoice_number']
            ?? $payload['merchant_invoice_id']
            ?? $payload['invoice']
            ?? null
        );
        $reason = self::stringOrNull( $payload['message_en'] ?? $payload['message_bn'] ?? $payload['reason'] ?? null )
            ?: ( 'RedX: ' . ( $status ?: 'webhook' ) );

        if ( ! $status && ! $trackingNumber && ! $invoice ) {
            return [
                'ok'      => true,
                'http'    => 202,
                'message' => 'RedX webhook handshake accepted',
                'action'  => 'handshake',
            ];
        }

        $targetStatus = self::mapStatusToOrderStatus( $status, $deliveryType );
        if ( ! $targetStatus ) {
            return [
                'ok'      => true,
                'http'    => 202,
                'message' => 'Event ignored (not delivered/returned).',
                'action'  => 'ignored',
            ];
        }

        if ( ! $trackingNumber && ! $invoice ) {
            return [
                'ok'      => false,
                'http'    => 422,
                'message' => 'tracking_number or invoice_number is required.',
            ];
        }

        $found = self::findRedxOrder( $trackingNumber, $invoice, $tenantId );
        if ( ! $found ) {
            Log::warning( 'RedX webhook order not found', [
                'tenant_id'       => $tenantId,
                'tracking_number' => $trackingNumber,
                'invoice_number'  => $invoice,
                'status'          => $status,
            ] );

            return [
                'ok'      => false,
                'http'    => 404,
                'message' => 'RedX order not found in system.',
            ];
        }

        /** @var Order $order */
        $order  = $found['order'];
        $tenant = $found['tenant'];

        try {
            if ( ! tenancy()->initialized || (string) tenant( 'id' ) !== (string) $tenant->id ) {
                tenancy()->initialize( $tenant );
            }

            $order = Order::query()
                ->where( 'id', $order->id )
                ->first();

            if ( ! $order ) {
                return [
                    'ok'      => false,
                    'http'    => 404,
                    'message' => 'Order missing after tenant init.',
                ];
            }

            $current = strtolower( (string) $order->status );
            if ( $current === $targetStatus ) {
                return [
                    'ok'       => true,
                    'http'     => 202,
                    'message'  => 'Order already in target status.',
                    'action'   => 'noop',
                    'order_id' => (int) $order->id,
                ];
            }

            if ( in_array( $current, ['delivered', 'return', 'cancel'], true ) && $current !== $targetStatus ) {
                return [
                    'ok'       => true,
                    'http'     => 202,
                    'message'  => "Order already finalized as {$current}; skipping {$targetStatus}.",
                    'action'   => 'skipped',
                    'order_id' => (int) $order->id,
                ];
            }

            if ( ! in_array( $current, ['progress', 'courier', 'processing', 'ready', 'received', 'pending'], true ) ) {
                return [
                    'ok'       => true,
                    'http'     => 202,
                    'message'  => "Order status {$current} is not eligible for RedX webhook update.",
                    'action'   => 'skipped',
                    'order_id' => (int) $order->id,
                ];
            }

            if ( $targetStatus === 'delivered' ) {
                $response = ProductOrderService::deliveredOrder( $order );
            } else {
                request()->merge( ['reason' => $reason] );
                $response = ProductOrderService::returnOrder( $order );
            }

            $statusCode = method_exists( $response, 'getStatusCode' ) ? $response->getStatusCode() : 200;
            $body       = method_exists( $response, 'getData' ) ? (array) $response->getData( true ) : [];

            if ( $statusCode >= 400 ) {
                return [
                    'ok'       => false,
                    'http'     => $statusCode,
                    'message'  => (string) ( $body['message'] ?? 'Failed to update order status.' ),
                    'action'   => $targetStatus,
                    'order_id' => (int) $order->id,
                ];
            }

            Log::info( 'RedX webhook updated order', [
                'tenant_id'       => $tenant->id,
                'order_id'        => $order->id,
                'tracking_number' => $trackingNumber,
                'status'          => $targetStatus,
            ] );

            return [
                'ok'       => true,
                'http'     => 202,
                'message'  => 'Order status updated from RedX webhook.',
                'action'   => $targetStatus,
                'order_id' => (int) $order->id,
            ];
        } catch ( Throwable $e ) {
            Log::error( 'RedX webhook failed', [
                'error'  => $e->getMessage(),
                'status' => $status,
            ] );

            return [
                'ok'      => false,
                'http'    => 500,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{order:Order,tenant:Tenant}|null
     */
    public static function findRedxOrder( ?string $trackingNumber, ?string $invoice, ?string $tenantId = null ): ?array
    {
        $tenants = $tenantId
            ? collect( [Tenant::on( 'mysql' )->find( $tenantId )] )->filter()
            : Tenant::on( 'mysql' )->whereNull( 'deleted_at' )->get();

        foreach ( $tenants as $tenant ) {
            try {
                $order = CrossTenantQueryService::getSingleFromTenant(
                    $tenant->id,
                    Order::class,
                    function ( $query ) use ( $trackingNumber, $invoice ) {
                        $query->where( function ( $q ) use ( $trackingNumber, $invoice ) {
                            $first = true;
                            if ( $trackingNumber ) {
                                $q->where( 'consignment_id', $trackingNumber )
                                    ->orWhere( 'delivery_id', 'like', '%' . $trackingNumber . '%' );
                                $first = false;
                            }
                            if ( $invoice ) {
                                $method = $first ? 'where' : 'orWhere';
                                $q->{$method}( 'order_id', $invoice );
                            }
                        } );

                        $query->where( function ( $q ) {
                            $q->where( 'courier_name', 'redx' )
                                ->orWhere( 'delivery_id', 'like', '%_+_redx%' )
                                ->orWhere( 'delivery_id', 'like', '%redx%' );
                        } );
                    }
                );

                if ( $order ) {
                    return [
                        'order'  => $order,
                        'tenant' => $tenant,
                    ];
                }
            } catch ( Throwable $e ) {
                Log::warning( 'RedX webhook tenant scan failed', [
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ] );
            }
        }

        if ( $trackingNumber ) {
            foreach ( $tenants as $tenant ) {
                try {
                    $order = CrossTenantQueryService::getSingleFromTenant(
                        $tenant->id,
                        Order::class,
                        function ( $query ) use ( $trackingNumber ) {
                            $query->where( 'consignment_id', $trackingNumber );
                        }
                    );
                    if ( $order ) {
                        return [
                            'order'  => $order,
                            'tenant' => $tenant,
                        ];
                    }
                } catch ( Throwable $e ) {
                    // continue
                }
            }
        }

        return null;
    }

    private static function stringOrNull( $value ): ?string
    {
        if ( $value === null ) {
            return null;
        }
        $value = trim( (string) $value );

        return $value === '' ? null : $value;
    }
}
