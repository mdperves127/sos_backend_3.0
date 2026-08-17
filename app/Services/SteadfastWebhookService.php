<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handle Steadfast (Packzy) merchant webhooks and sync order status.
 *
 * Configure in Steadfast dashboard:
 *   Callback URL + Auth Token (Bearer)
 *
 * Webhook statuses of interest:
 * - delivered / partial_delivered → delivered
 * - cancelled → return
 *
 * @see https://docs.google.com/document/d/e/2PACX-1vTi0sTyR353xu1AK0nR8E_WKe5onCkUXGEf8ch8uoJy9qxGfgGnboSIkNosjQ0OOdXkJhgGuAsWxnIh/pub
 */
class SteadfastWebhookService
{
    public const DELIVERED_STATUSES = [
        'delivered',
        'partial_delivered',
        'delivered_approval_pending',
        'partial_delivered_approval_pending',
    ];

    public const RETURN_STATUSES = [
        'cancelled',
        'cancelled_approval_pending',
    ];

    public static function webhookBearer(): string
    {
        return (string) config(
            'services.steadfast.webhook_bearer',
            env( 'STEADFAST_WEBHOOK_BEARER', env( 'STEADFAST_WEBHOOK_SECRET', '' ) )
        );
    }

    public static function mapStatusToOrderStatus( ?string $status ): ?string
    {
        $status = strtolower( trim( (string) $status ) );
        $status = str_replace( [' ', '-'], '_', $status );

        if ( in_array( $status, self::DELIVERED_STATUSES, true ) ) {
            return 'delivered';
        }

        if ( in_array( $status, self::RETURN_STATUSES, true ) ) {
            return 'return';
        }

        return null;
    }

    /**
     * @return array{ok:bool,http:int,message:string,action?:string|null,order_id?:int|null}
     */
    public static function handle( array $payload, ?string $tenantId = null ): array
    {
        $notificationType = strtolower( trim( (string) ( $payload['notification_type'] ?? '' ) ) );
        $status           = self::stringOrNull(
            $payload['status']
            ?? $payload['delivery_status']
            ?? $payload['order_status']
            ?? null
        );

        if ( in_array( $notificationType, ['webhook_integration', 'ping', 'test'], true ) && ! $status ) {
            return [
                'ok'      => true,
                'http'    => 202,
                'message' => 'Steadfast webhook handshake accepted',
                'action'  => 'handshake',
            ];
        }

        $targetStatus = self::mapStatusToOrderStatus( $status );
        if ( ! $targetStatus ) {
            return [
                'ok'      => true,
                'http'    => 202,
                'message' => 'Event ignored (not delivered/cancelled).',
                'action'  => 'ignored',
            ];
        }

        $consignmentId = self::stringOrNull( $payload['consignment_id'] ?? $payload['consignmentID'] ?? null );
        $invoice       = self::stringOrNull( $payload['invoice'] ?? $payload['merchant_order_id'] ?? null );
        $trackingCode  = self::stringOrNull( $payload['tracking_code'] ?? $payload['trackingCode'] ?? null );
        $reason        = self::stringOrNull( $payload['reason'] ?? $payload['tracking_message'] ?? null )
            ?: ( 'Steadfast: ' . ( $status ?: $notificationType ) );

        if ( ! $consignmentId && ! $invoice && ! $trackingCode ) {
            return [
                'ok'      => false,
                'http'    => 422,
                'message' => 'consignment_id, invoice, or tracking_code is required.',
            ];
        }

        $found = self::findSteadfastOrder( $consignmentId, $invoice, $trackingCode, $tenantId );
        if ( ! $found ) {
            Log::warning( 'Steadfast webhook order not found', [
                'tenant_id'      => $tenantId,
                'consignment_id' => $consignmentId,
                'invoice'        => $invoice,
                'tracking_code'  => $trackingCode,
                'status'         => $status,
            ] );

            return [
                'ok'      => false,
                'http'    => 404,
                'message' => 'Steadfast order not found in system.',
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
                    'message'  => "Order status {$current} is not eligible for Steadfast webhook update.",
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

            Log::info( 'Steadfast webhook updated order', [
                'tenant_id'      => $tenant->id,
                'order_id'       => $order->id,
                'consignment_id' => $consignmentId,
                'status'         => $targetStatus,
            ] );

            return [
                'ok'       => true,
                'http'     => 202,
                'message'  => 'Order status updated from Steadfast webhook.',
                'action'   => $targetStatus,
                'order_id' => (int) $order->id,
            ];
        } catch ( Throwable $e ) {
            Log::error( 'Steadfast webhook failed', [
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
    public static function findSteadfastOrder( ?string $consignmentId, ?string $invoice, ?string $trackingCode, ?string $tenantId = null ): ?array
    {
        $tenants = $tenantId
            ? collect( [Tenant::on( 'mysql' )->find( $tenantId )] )->filter()
            : Tenant::on( 'mysql' )->whereNull( 'deleted_at' )->get();

        foreach ( $tenants as $tenant ) {
            try {
                $order = CrossTenantQueryService::getSingleFromTenant(
                    $tenant->id,
                    Order::class,
                    function ( $query ) use ( $consignmentId, $invoice, $trackingCode ) {
                        $query->where( function ( $q ) use ( $consignmentId, $invoice, $trackingCode ) {
                            $first = true;
                            if ( $consignmentId ) {
                                $q->where( 'consignment_id', $consignmentId );
                                $first = false;
                            }
                            if ( $invoice ) {
                                $method = $first ? 'where' : 'orWhere';
                                $q->{$method}( 'order_id', $invoice );
                                $first = false;
                            }
                            if ( $trackingCode ) {
                                $method = $first ? 'where' : 'orWhere';
                                $q->{$method}( 'delivery_id', 'like', '%' . $trackingCode . '%' );
                            }
                        } );

                        $query->where( function ( $q ) {
                            $q->where( 'courier_name', 'steadfast' )
                                ->orWhere( 'delivery_id', 'like', '%_+_steadfast%' )
                                ->orWhere( 'delivery_id', 'like', '%steadfast%' );
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
                Log::warning( 'Steadfast webhook tenant scan failed', [
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ] );
            }
        }

        if ( $consignmentId ) {
            foreach ( $tenants as $tenant ) {
                try {
                    $order = CrossTenantQueryService::getSingleFromTenant(
                        $tenant->id,
                        Order::class,
                        function ( $query ) use ( $consignmentId ) {
                            $query->where( 'consignment_id', $consignmentId );
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
