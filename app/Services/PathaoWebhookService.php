<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Handle Pathao merchant webhooks and sync order status (Pathao only).
 *
 * Events of interest:
 * - order.delivered / order.partial-delivery → delivered
 * - order.returned / order.returned-to-merchant / order.paid-return → return
 */
class PathaoWebhookService
{
    public const DELIVERED_EVENTS = [
        'order.delivered',
        'order.partial-delivery',
    ];

    public const RETURN_EVENTS = [
        'order.returned',
        'order.returned-to-merchant',
        'order.paid-return',
    ];

    public static function webhookSecret(): string
    {
        return (string) config( 'services.pathao.webhook_secret', env( 'PATHAO_WEBHOOK_SECRET', '' ) );
    }

    public static function mapEventToStatus( string $event, ?string $orderStatus = null ): ?string
    {
        $event = strtolower( trim( $event ) );
        $orderStatus = strtolower( trim( (string) $orderStatus ) );

        if ( in_array( $event, self::DELIVERED_EVENTS, true ) || $orderStatus === 'delivered' ) {
            return 'delivered';
        }

        if (
            in_array( $event, self::RETURN_EVENTS, true )
            || in_array( $orderStatus, ['returned', 'return', 'paid_return', 'returned_to_merchant'], true )
        ) {
            return 'return';
        }

        return null;
    }

    /**
     * @return array{ok:bool,http:int,message:string,action?:string|null,order_id?:int|null}
     */
    public static function handle( array $payload, ?string $tenantId = null ): array
    {
        $event = (string) ( $payload['event'] ?? '' );

        if ( $event === 'webhook_integration' || $event === 'webhook.integration' ) {
            return [
                'ok'      => true,
                'http'    => 202,
                'message' => 'webhook_integration accepted',
                'action'  => 'handshake',
            ];
        }

        $targetStatus = self::mapEventToStatus( $event, $payload['order_status'] ?? null );
        if ( ! $targetStatus ) {
            return [
                'ok'      => true,
                'http'    => 202,
                'message' => 'Event ignored (not delivered/return).',
                'action'  => 'ignored',
            ];
        }

        $consignmentId    = self::stringOrNull( $payload['consignment_id'] ?? null );
        $merchantOrderId  = self::stringOrNull( $payload['merchant_order_id'] ?? null );
        $reason           = self::stringOrNull( $payload['reason'] ?? null ) ?: ( 'Pathao: ' . $event );

        if ( ! $consignmentId && ! $merchantOrderId ) {
            return [
                'ok'      => false,
                'http'    => 422,
                'message' => 'consignment_id or merchant_order_id is required.',
            ];
        }

        $found = self::findPathaoOrder( $consignmentId, $merchantOrderId, $tenantId );
        if ( ! $found ) {
            Log::warning( 'Pathao webhook order not found', [
                'tenant_id'          => $tenantId,
                'consignment_id'     => $consignmentId,
                'merchant_order_id'  => $merchantOrderId,
                'event'              => $event,
            ] );

            return [
                'ok'      => false,
                'http'    => 404,
                'message' => 'Pathao order not found in system.',
            ];
        }

        /** @var Order $order */
        $order = $found['order'];
        $tenant = $found['tenant'];

        try {
            if ( ! tenancy()->initialized || (string) tenant( 'id' ) !== (string) $tenant->id ) {
                tenancy()->initialize( $tenant );
            }

            // Reload on tenant connection.
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

            Log::info( 'Pathao webhook updated order', [
                'tenant_id'      => $tenant->id,
                'order_id'       => $order->id,
                'consignment_id' => $consignmentId,
                'event'          => $event,
                'status'         => $targetStatus,
            ] );

            return [
                'ok'       => true,
                'http'     => 202,
                'message'  => 'Order status updated from Pathao webhook.',
                'action'   => $targetStatus,
                'order_id' => (int) $order->id,
            ];
        } catch ( Throwable $e ) {
            Log::error( 'Pathao webhook failed', [
                'error' => $e->getMessage(),
                'event' => $event,
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
    public static function findPathaoOrder( ?string $consignmentId, ?string $merchantOrderId, ?string $tenantId = null ): ?array
    {
        $tenants = $tenantId
            ? collect( [Tenant::on( 'mysql' )->find( $tenantId )] )->filter()
            : Tenant::on( 'mysql' )->whereNull( 'deleted_at' )->get();

        foreach ( $tenants as $tenant ) {
            try {
                $order = CrossTenantQueryService::getSingleFromTenant(
                    $tenant->id,
                    Order::class,
                    function ( $query ) use ( $consignmentId, $merchantOrderId ) {
                        $query->where( function ( $q ) use ( $consignmentId, $merchantOrderId ) {
                            if ( $consignmentId ) {
                                $q->where( 'consignment_id', $consignmentId );
                            }
                            if ( $merchantOrderId ) {
                                $method = $consignmentId ? 'orWhere' : 'where';
                                $q->{$method}( 'order_id', $merchantOrderId );
                            }
                        } );

                        $query->where( function ( $q ) {
                            $q->where( 'courier_name', 'pathao' )
                                ->orWhere( 'delivery_id', 'like', '%_+_pathao%' )
                                ->orWhere( 'delivery_id', 'like', '%pathao%' );
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
                Log::warning( 'Pathao webhook tenant scan failed', [
                    'tenant_id' => $tenant->id,
                    'error'     => $e->getMessage(),
                ] );
            }
        }

        // Fallback: match by consignment only (courier_name may be missing on older rows).
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
