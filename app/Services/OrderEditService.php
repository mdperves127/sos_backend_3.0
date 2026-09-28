<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderDetails;
use App\Models\OrderDeliveryToCourier;
use App\Models\OrderEditHistory;
use App\Models\Product;
use App\Service\Vendor\ProductVariantService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Merchant (tenant) existing-order edit: lines, discount, delivery, customer/address, audit.
 */
class OrderEditService
{
    /** Statuses that must not be edited. */
    public const LOCKED_STATUSES = ['cancel', 'delivered', 'return', 'courier'];

    /**
     * @return array{order: Order, items: array<int, array<string, mixed>>, editable: bool, locked_reason: ?string}
     */
    public function getEditableOrder( int $orderId, int $vendorId ): array
    {
        $order = Order::where( 'id', $orderId )
            ->where( 'vendor_id', $vendorId )
            ->with( [
                'product:id,name,image,sku,selling_price,discount_price',
                'orderDetails',
                'pickupArea:id,address',
                'deliveryArea:id,address',
            ] )
            ->first();

        if ( ! $order ) {
            throw new RuntimeException( 'Order not found' );
        }

        $lockedReason = $this->editLockReason( $order );

        return [
            'order'         => $order,
            'items'         => $this->resolveCurrentItems( $order ),
            'editable'      => $lockedReason === null,
            'locked_reason' => $lockedReason,
            'totals'        => $this->snapshotTotals( $order ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update( int $orderId, int $vendorId, array $payload, int $actorUserId ): Order
    {
        return DB::transaction( function () use ( $orderId, $vendorId, $payload, $actorUserId ) {
            $order = Order::where( 'id', $orderId )
                ->where( 'vendor_id', $vendorId )
                ->lockForUpdate()
                ->first();

            if ( ! $order ) {
                throw new RuntimeException( 'Order not found' );
            }

            if ( $reason = $this->editLockReason( $order ) ) {
                throw new RuntimeException( $reason );
            }

            $audits = [];
            $before = $this->snapshotOrder( $order );

            $this->applyCustomerAndAddress( $order, $payload, $audits );
            $this->applyDiscountAndDelivery( $order, $payload, $audits );

            if ( array_key_exists( 'items', $payload ) ) {
                $this->applyItems( $order, $payload['items'] ?? [], $vendorId, $audits );
            } elseif ( array_key_exists( 'qty', $payload ) && ! $order->orderDetails()->exists() ) {
                $this->applyVariantOnlyQuantity( $order, (int) $payload['qty'], $vendorId, $audits );
            }

            if ( array_key_exists( 'paid_amount', $payload ) && $this->isDirectStyle( $order ) ) {
                $oldPaid = (float) ( $order->paid_amount ?? 0 );
                $newPaid = round( (float) $payload['paid_amount'], 2 );
                if ( $newPaid < 0 ) {
                    throw new RuntimeException( 'Paid amount cannot be negative.' );
                }
                if ( abs( $oldPaid - $newPaid ) > 0.00001 ) {
                    $order->paid_amount = $newPaid;
                    $audits[] = $this->auditDraft(
                        'paid_amount_changed',
                        'paid_amount',
                        $oldPaid,
                        $newPaid
                    );
                }
            }

            $this->recalculateTotals( $order, $before, $audits );
            $order->save();

            $this->syncCourierRecipientSnapshot( $order );

            if ( $audits === [] ) {
                throw new RuntimeException( 'No changes detected.' );
            }

            foreach ( $audits as $audit ) {
                OrderEditHistory::create( [
                    'order_id'  => $order->id,
                    'user_id'   => $actorUserId,
                    'action'    => $audit['action'],
                    'field'     => $audit['field'],
                    'old_value' => $this->stringifyAuditValue( $audit['old_value'] ),
                    'new_value' => $this->stringifyAuditValue( $audit['new_value'] ),
                    'metadata'  => $audit['metadata'] ?? null,
                ] );
            }

            return $order->fresh( [
                'product:id,name,image,sku',
                'orderDetails',
                'pickupArea:id,address',
                'deliveryArea:id,address',
            ] );
        } );
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Support\Collection
     */
    public function history( int $orderId, int $vendorId, int $perPage = 20 )
    {
        $exists = Order::where( 'id', $orderId )->where( 'vendor_id', $vendorId )->exists();
        if ( ! $exists ) {
            throw new RuntimeException( 'Order not found' );
        }

        return OrderEditHistory::where( 'order_id', $orderId )
            ->with( 'user:id,name,email,role_type' )
            ->latest( 'id' )
            ->paginate( max( 1, min( 100, $perPage ) ) );
    }

    public function editLockReason( Order $order ): ?string
    {
        $status = strtolower( (string) ( $order->status ?? '' ) );

        if ( in_array( $status, self::LOCKED_STATUSES, true ) ) {
            return 'Orders with status "' . $status . '" cannot be edited.';
        }

        return null;
    }

    public function assertCanEdit(): void
    {
        if ( ! Auth::check() ) {
            throw new RuntimeException( 'Unauthorized' );
        }

        // Tenant owner / non-employee merchant users can always edit their orders.
        if ( isTenantAdmin() || ( Auth::user()->role_type ?? null ) !== 'employee' ) {
            return;
        }

        // Employees need an explicit order permission flag.
        $allowed = tenantPermission( 'edit_order' )
            || tenantPermission( 'order' )
            || tenantPermission( 'add_order' )
            || tenantPermission( 'all_order' );

        if ( ! $allowed ) {
            throw new RuntimeException( 'You do not have permission to edit orders.' );
        }
    }

    /**
     * Server-side grand total using existing merchant formula.
     */
    public static function calculateDueAmount(
        float $productAmount,
        float $deliveryCharge,
        float $saleDiscount,
        float $paidAmount = 0,
        float $advancePayment = 0
    ): float {
        $due = ( $productAmount + $deliveryCharge ) - $saleDiscount - $paidAmount - $advancePayment;

        return round( max( 0, $due ), 2 );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function resolveCurrentItems( Order $order ): array
    {
        $details = OrderDetails::where( 'order_id', $order->id )->get();

        if ( $details->isNotEmpty() ) {
            return $details->map( function ( OrderDetails $row ) {
                return [
                    'id'           => $row->id,
                    'product_id'   => (int) $row->product_id,
                    'product_name' => $row->product?->name,
                    'unit_id'      => $row->unit_id,
                    'size_id'      => $row->size_id,
                    'color_id'     => $row->color_id,
                    'qty'          => (int) $row->sub_qty,
                    'rate'         => (float) $row->rate,
                    'sub_total'    => (float) $row->sub_total,
                    'source'       => 'order_details',
                ];
            } )->values()->all();
        }

        if ( ! $order->product_id ) {
            return [];
        }

        $variants = Order::normalizeVariants( $order->variants );
        $qty      = (int) ( $order->qty ?? 0 );
        if ( $qty < 1 && $variants !== [] ) {
            $qty = 0;
            foreach ( $variants as $variant ) {
                $variant = (array) $variant;
                $qty += (int) ( $variant['qty'] ?? $variant['quantity'] ?? 0 );
            }
        }

        $rate = $qty > 0
            ? round( (float) $order->product_amount / $qty, 2 )
            : (float) ( $order->product_amount ?? 0 );

        return [[
            'id'           => null,
            'product_id'   => (int) $order->product_id,
            'product_name' => $order->product?->name,
            'unit_id'      => null,
            'size_id'      => null,
            'color_id'     => null,
            'qty'          => max( 1, $qty ),
            'rate'         => $rate,
            'sub_total'    => (float) ( $order->product_amount ?? 0 ),
            'variants'     => $variants,
            'source'       => 'variants',
        ]];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $audits
     */
    private function applyCustomerAndAddress( Order $order, array $payload, array &$audits ): void
    {
        $map = [
            'name'            => 'customer_name_changed',
            'phone'           => 'customer_phone_changed',
            'email'           => 'customer_email_changed',
            'city'            => 'customer_city_changed',
            'address'         => 'address_changed',
            'delivery_area'   => 'delivery_area_changed',
            'pickup_area'     => 'pickup_area_changed',
            'additional_note' => 'additional_note_changed',
            'internal_note'   => 'internal_note_changed',
            'shipping_date'   => 'shipping_date_changed',
        ];

        // Nested customer object support.
        $customer = is_array( $payload['customer'] ?? null ) ? $payload['customer'] : [];
        foreach ( ['name', 'phone', 'email', 'city', 'address'] as $key ) {
            if ( array_key_exists( $key, $customer ) && ! array_key_exists( $key, $payload ) ) {
                $payload[$key] = $customer[$key];
            }
        }

        foreach ( $map as $field => $action ) {
            if ( ! array_key_exists( $field, $payload ) ) {
                continue;
            }

            $old = $order->{$field};
            $new = $payload[$field];

            if ( in_array( $field, ['delivery_area', 'pickup_area'], true ) ) {
                $new = $new === null || $new === '' ? null : (int) $new;
                $old = $old === null || $old === '' ? null : (int) $old;
            } else {
                $new = $new === null ? null : (string) $new;
                $old = $old === null ? null : (string) $old;
            }

            if ( (string) ( $old ?? '' ) === (string) ( $new ?? '' ) ) {
                continue;
            }

            if ( in_array( $field, ['name', 'phone', 'address'], true ) && trim( (string) $new ) === '' ) {
                throw new RuntimeException( ucfirst( $field ) . ' is required.' );
            }

            if ( $field === 'phone' && strlen( preg_replace( '/\D+/', '', (string) $new ) ?? '' ) < 10 ) {
                throw new RuntimeException( 'Phone must be at least 10 digits.' );
            }

            if ( $field === 'email' && $new !== null && $new !== '' && ! filter_var( $new, FILTER_VALIDATE_EMAIL ) ) {
                throw new RuntimeException( 'Invalid email address.' );
            }

            $order->{$field} = $new;
            $audits[] = $this->auditDraft( $action, $field, $old, $new );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $audits
     */
    private function applyDiscountAndDelivery( Order $order, array $payload, array &$audits ): void
    {
        if ( array_key_exists( 'sale_discount', $payload ) || array_key_exists( 'discount', $payload ) ) {
            $old = round( (float) ( $order->sale_discount ?? 0 ), 2 );
            $new = round( (float) ( $payload['sale_discount'] ?? $payload['discount'] ), 2 );

            if ( $new < 0 ) {
                throw new RuntimeException( 'Discount cannot be negative.' );
            }

            if ( abs( $old - $new ) > 0.00001 ) {
                $action = $old <= 0 && $new > 0
                    ? 'discount_added'
                    : ( $new <= 0 ? 'discount_removed' : 'discount_changed' );

                $order->sale_discount = $new;
                $audits[] = $this->auditDraft( $action, 'sale_discount', $old, $new );
            }
        }

        if ( array_key_exists( 'delivery_charge', $payload ) ) {
            $old = round( (float) ( $order->delivery_charge ?? 0 ), 2 );
            $new = round( (float) $payload['delivery_charge'], 2 );

            if ( $new < 0 ) {
                throw new RuntimeException( 'Delivery charge cannot be negative.' );
            }

            if ( abs( $old - $new ) > 0.00001 ) {
                $order->delivery_charge = $new;
                $audits[] = $this->auditDraft( 'delivery_charge_changed', 'delivery_charge', $old, $new );
            }
        }
    }

    /**
     * Replace order lines with the provided set. Missing existing ids are removed.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $audits
     */
    private function applyItems( Order $order, array $items, int $vendorId, array &$audits ): void
    {
        if ( $items === [] ) {
            throw new RuntimeException( 'Order must contain at least one product.' );
        }

        $normalized = [];
        foreach ( $items as $index => $item ) {
            if ( ! is_array( $item ) ) {
                throw new RuntimeException( 'Invalid product item at index ' . $index );
            }

            $productId = (int) ( $item['product_id'] ?? 0 );
            $qty       = (int) ( $item['qty'] ?? $item['sub_qty'] ?? 0 );
            $rate      = round( (float) ( $item['rate'] ?? 0 ), 2 );

            if ( $productId < 1 ) {
                throw new RuntimeException( 'Product is required for each line item.' );
            }
            if ( $qty < 1 ) {
                throw new RuntimeException( 'Quantity must be at least 1.' );
            }
            if ( $rate < 0 ) {
                throw new RuntimeException( 'Rate cannot be negative.' );
            }

            $product = Product::where( 'id', $productId )
                ->where( 'vendor_id', $vendorId )
                ->first();

            if ( ! $product ) {
                throw new RuntimeException( 'Invalid product selected.' );
            }

            if ( $rate <= 0 ) {
                $rate = (float) ( $product->discount_price ?: $product->selling_price ?: 0 );
            }

            $normalized[] = [
                'id'         => isset( $item['id'] ) && $item['id'] !== null && $item['id'] !== ''
                    ? (int) $item['id']
                    : null,
                'product_id' => $productId,
                'product'    => $product,
                'unit_id'    => ProductVariantService::normalizeNullableId( $item['unit_id'] ?? null ),
                'size_id'    => ProductVariantService::normalizeNullableId( $item['size_id'] ?? null ),
                'color_id'   => ProductVariantService::normalizeNullableId( $item['color_id'] ?? null ),
                'qty'        => $qty,
                'rate'       => $rate,
                'sub_total'  => round( $rate * $qty, 2 ),
            ];
        }

        $existingDetails = OrderDetails::where( 'order_id', $order->id )->get()->keyBy( 'id' );
        $usesDetails     = $existingDetails->isNotEmpty() || count( $normalized ) > 1 || $this->isDirectStyle( $order );

        if ( ! $usesDetails ) {
            // Keep single-product storefront shape (variants JSON).
            $line = $normalized[0];
            $this->applyVariantOnlyQuantity(
                $order,
                $line['qty'],
                $vendorId,
                $audits,
                $line['product_id'],
                $line['rate'],
                $line['unit_id'],
                $line['size_id'],
                $line['color_id']
            );

            return;
        }

        // Ensure Direct-style details exist when expanding a variants-only order.
        if ( $existingDetails->isEmpty() && $order->product_id ) {
            $this->migrateVariantsToDetails( $order, $vendorId );
            $existingDetails = OrderDetails::where( 'order_id', $order->id )->get()->keyBy( 'id' );
        }

        $keptIds = [];

        foreach ( $normalized as $line ) {
            if ( $line['id'] && $existingDetails->has( $line['id'] ) ) {
                /** @var OrderDetails $detail */
                $detail = $existingDetails->get( $line['id'] );
                $keptIds[] = $detail->id;

                $oldQty  = (int) $detail->sub_qty;
                $oldRate = (float) $detail->rate;
                $delta   = $line['qty'] - $oldQty;

                if ( (int) $detail->product_id !== $line['product_id'] ) {
                    throw new RuntimeException( 'Changing product on an existing line is not supported. Remove and add instead.' );
                }

                if ( $delta !== 0 ) {
                    $this->adjustStock(
                        $order,
                        $line['product_id'],
                        $line['unit_id'],
                        $line['size_id'],
                        $line['color_id'],
                        $delta,
                        $vendorId
                    );

                    $audits[] = $this->auditDraft(
                        'quantity_changed',
                        'quantity',
                        $oldQty,
                        $line['qty'],
                        [
                            'order_detail_id' => $detail->id,
                            'product_id'      => $line['product_id'],
                            'product_name'    => $line['product']->name ?? null,
                        ]
                    );
                }

                if ( abs( $oldRate - $line['rate'] ) > 0.00001 ) {
                    $audits[] = $this->auditDraft(
                        'rate_changed',
                        'rate',
                        $oldRate,
                        $line['rate'],
                        [
                            'order_detail_id' => $detail->id,
                            'product_id'      => $line['product_id'],
                            'product_name'    => $line['product']->name ?? null,
                        ]
                    );
                }

                $detail->unit_id   = $line['unit_id'];
                $detail->size_id   = $line['size_id'];
                $detail->color_id  = $line['color_id'];
                $detail->sub_qty   = $line['qty'];
                $detail->rate      = $line['rate'];
                $detail->sub_total = $line['sub_total'];
                $detail->save();

                continue;
            }

            $detail = OrderDetails::create( [
                'order_id'   => $order->id,
                'product_id' => $line['product_id'],
                'unit_id'    => $line['unit_id'],
                'size_id'    => $line['size_id'],
                'color_id'   => $line['color_id'],
                'sub_qty'    => $line['qty'],
                'rate'       => $line['rate'],
                'sub_total'  => $line['sub_total'],
            ] );

            $this->adjustStock(
                $order,
                $line['product_id'],
                $line['unit_id'],
                $line['size_id'],
                $line['color_id'],
                $line['qty'],
                $vendorId
            );

            $keptIds[] = $detail->id;
            $audits[] = $this->auditDraft(
                'product_added',
                'product',
                null,
                $line['product']->name ?? (string) $line['product_id'],
                [
                    'order_detail_id' => $detail->id,
                    'product_id'      => $line['product_id'],
                    'product_name'    => $line['product']->name ?? null,
                    'quantity'        => $line['qty'],
                    'rate'            => $line['rate'],
                ]
            );
        }

        foreach ( $existingDetails as $detail ) {
            if ( in_array( $detail->id, $keptIds, true ) ) {
                continue;
            }

            $this->adjustStock(
                $order,
                (int) $detail->product_id,
                ProductVariantService::normalizeNullableId( $detail->unit_id ),
                ProductVariantService::normalizeNullableId( $detail->size_id ),
                ProductVariantService::normalizeNullableId( $detail->color_id ),
                -1 * (int) $detail->sub_qty,
                $vendorId
            );

            $audits[] = $this->auditDraft(
                'product_removed',
                'product',
                $detail->product?->name ?? (string) $detail->product_id,
                null,
                [
                    'order_detail_id' => $detail->id,
                    'product_id'      => (int) $detail->product_id,
                    'product_name'    => $detail->product?->name,
                    'quantity'        => (int) $detail->sub_qty,
                    'rate'            => (float) $detail->rate,
                ]
            );

            $detail->delete();
        }

        // Keep header product_id pointing at first line for backward compatibility.
        $first = OrderDetails::where( 'order_id', $order->id )->orderBy( 'id' )->first();
        if ( $first ) {
            $order->product_id = $first->product_id;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $audits
     */
    private function applyVariantOnlyQuantity(
        Order $order,
        int $newQty,
        int $vendorId,
        array &$audits,
        ?int $productId = null,
        ?float $rate = null,
        ?int $unitId = null,
        ?int $sizeId = null,
        ?int $colorId = null
    ): void {
        if ( $newQty < 1 ) {
            throw new RuntimeException( 'Quantity must be at least 1.' );
        }

        $oldQty = (int) ( $order->qty ?? 0 );
        $variants = Order::normalizeVariants( $order->variants );

        if ( $oldQty < 1 && $variants !== [] ) {
            $oldQty = 0;
            foreach ( $variants as $variant ) {
                $variant = (array) $variant;
                $oldQty += (int) ( $variant['qty'] ?? $variant['quantity'] ?? 0 );
            }
        }

        $productId = $productId ?: (int) $order->product_id;
        if ( $productId < 1 ) {
            throw new RuntimeException( 'Order has no product to update.' );
        }

        $product = Product::where( 'id', $productId )->where( 'vendor_id', $vendorId )->first();
        if ( ! $product ) {
            throw new RuntimeException( 'Invalid product selected.' );
        }

        if ( $rate === null || $rate <= 0 ) {
            $rate = $oldQty > 0
                ? round( (float) $order->product_amount / $oldQty, 2 )
                : (float) ( $product->discount_price ?: $product->selling_price ?: 0 );
        }

        $delta = $newQty - $oldQty;

        if ( $delta !== 0 ) {
            if ( $variants !== [] ) {
                $unit = $variants[0] ?? [];
                $unit = (array) $unit;
                $unitId  = $unitId ?? ProductVariantService::normalizeNullableId(
                    $unit['unit_id'] ?? ( is_array( $unit['unit'] ?? null ) ? ( $unit['unit']['id'] ?? null ) : ( $unit['unit'] ?? null ) )
                );
                $sizeId  = $sizeId ?? ProductVariantService::normalizeNullableId(
                    $unit['size_id'] ?? ( is_array( $unit['size'] ?? null ) ? ( $unit['size']['id'] ?? null ) : ( $unit['size'] ?? $unit['variation'] ?? null ) )
                );
                $colorId = $colorId ?? ProductVariantService::normalizeNullableId(
                    $unit['color_id'] ?? ( is_array( $unit['color'] ?? null ) ? ( $unit['color']['id'] ?? null ) : ( $unit['color'] ?? null ) )
                );
            }

            $this->adjustStock( $order, $productId, $unitId, $sizeId, $colorId, $delta, $vendorId );

            $audits[] = $this->auditDraft(
                'quantity_changed',
                'quantity',
                $oldQty,
                $newQty,
                [
                    'product_id'   => $productId,
                    'product_name' => $product->name,
                ]
            );
        }

        if ( (int) $order->product_id !== $productId ) {
            $audits[] = $this->auditDraft(
                'product_changed',
                'product_id',
                $order->product_id,
                $productId,
                ['product_name' => $product->name]
            );
            $order->product_id = $productId;
        }

        if ( $variants !== [] ) {
            $variants[0] = (array) ( $variants[0] ?? [] );
            $variants[0]['qty'] = $newQty;
            $variants[0]['quantity'] = $newQty;
            $order->variants = $variants;
        }

        $order->qty = $newQty;
        $order->product_amount = round( $rate * $newQty, 2 );

        if ( $oldQty > 0 ) {
            $order->afi_amount = round( ( (float) ( $order->afi_amount ?? 0 ) / $oldQty ) * $newQty, 2 );
            $order->profit_amount = round( ( (float) ( $order->profit_amount ?? 0 ) / $oldQty ) * $newQty, 2 );
            $order->totaladvancepayment = round( ( (float) ( $order->totaladvancepayment ?? 0 ) / $oldQty ) * $newQty, 2 );
        }
    }

    private function migrateVariantsToDetails( Order $order, int $vendorId ): void
    {
        $items = $this->resolveCurrentItems( $order );
        foreach ( $items as $item ) {
            if ( ( $item['source'] ?? null ) !== 'variants' ) {
                continue;
            }

            OrderDetails::create( [
                'order_id'   => $order->id,
                'product_id' => $item['product_id'],
                'unit_id'    => $item['unit_id'] ?? null,
                'size_id'    => $item['size_id'] ?? null,
                'color_id'   => $item['color_id'] ?? null,
                'sub_qty'    => $item['qty'],
                'rate'       => $item['rate'],
                'sub_total'  => $item['sub_total'],
            ] );
        }

        // Stock already reserved for the migrated qty — no stock change here.
        if ( $order->order_media !== 'Direct' && $order->order_media !== null ) {
            // Keep original order_media; details are additive storage for multi-line edits.
        }
    }

    private function adjustStock(
        Order $order,
        int $productId,
        ?int $unitId,
        ?int $sizeId,
        ?int $colorId,
        int $deltaQty,
        int $vendorId
    ): void {
        if ( $deltaQty === 0 ) {
            return;
        }

        if ( (int) ( $order->is_unlimited ?? 0 ) === 1 ) {
            return;
        }

        if ( $deltaQty > 0 ) {
            ProductVariantService::decrementStock(
                $productId,
                $unitId,
                $sizeId,
                $colorId,
                $deltaQty,
                $vendorId
            );
        } else {
            ProductVariantService::incrementStock(
                $productId,
                $unitId,
                $sizeId,
                $colorId,
                abs( $deltaQty ),
                $vendorId
            );
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<int, array<string, mixed>>  $audits
     */
    private function recalculateTotals( Order $order, array $before, array &$audits ): void
    {
        $details = OrderDetails::where( 'order_id', $order->id )->get();

        if ( $details->isNotEmpty() ) {
            $productAmount = round( (float) $details->sum( 'sub_total' ), 2 );
            $qty           = (int) $details->sum( 'sub_qty' );
            $order->product_amount = $productAmount;
            $order->qty            = $qty;
        }

        $productAmount = round( (float) ( $order->product_amount ?? 0 ), 2 );
        $delivery      = round( (float) ( $order->delivery_charge ?? 0 ), 2 );
        $discount      = round( (float) ( $order->sale_discount ?? 0 ), 2 );
        $paid          = round( (float) ( $order->paid_amount ?? 0 ), 2 );
        $advance       = round( (float) ( $order->totaladvancepayment ?? 0 ), 2 );

        if ( $discount > ( $productAmount + $delivery ) ) {
            throw new RuntimeException( 'Discount cannot exceed product amount plus delivery charge.' );
        }

        $due = self::calculateDueAmount( $productAmount, $delivery, $discount, $paid, $advance );

        if ( abs( (float) ( $before['due_amount'] ?? 0 ) - $due ) > 0.00001 ) {
            $audits[] = $this->auditDraft(
                'due_amount_changed',
                'due_amount',
                $before['due_amount'] ?? 0,
                $due
            );
        }

        if ( abs( (float) ( $before['product_amount'] ?? 0 ) - $productAmount ) > 0.00001
            && ! $this->auditsContainField( $audits, 'quantity' )
            && ! $this->auditsContainAction( $audits, 'product_added' )
            && ! $this->auditsContainAction( $audits, 'product_removed' )
        ) {
            $audits[] = $this->auditDraft(
                'product_amount_changed',
                'product_amount',
                $before['product_amount'] ?? 0,
                $productAmount
            );
        }

        $order->due_amount = $due;
    }

    private function syncCourierRecipientSnapshot( Order $order ): void
    {
        $row = OrderDeliveryToCourier::where( 'order_id', $order->id )->first();
        if ( ! $row ) {
            return;
        }

        $row->recipient_name    = $order->name;
        $row->recipient_phone   = $order->phone;
        $row->recipient_address = $order->address;
        $row->amount_to_collect = $order->due_amount;
        $row->save();
    }

    private function isDirectStyle( Order $order ): bool
    {
        return ( $order->order_media ?? null ) === 'Direct'
            || OrderDetails::where( 'order_id', $order->id )->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotOrder( Order $order ): array
    {
        return [
            'name'                 => $order->name,
            'phone'                => $order->phone,
            'email'                => $order->email,
            'city'                 => $order->city,
            'address'              => $order->address,
            'product_amount'       => (float) ( $order->product_amount ?? 0 ),
            'delivery_charge'      => (float) ( $order->delivery_charge ?? 0 ),
            'sale_discount'        => (float) ( $order->sale_discount ?? 0 ),
            'paid_amount'          => (float) ( $order->paid_amount ?? 0 ),
            'due_amount'           => (float) ( $order->due_amount ?? 0 ),
            'totaladvancepayment'  => (float) ( $order->totaladvancepayment ?? 0 ),
            'qty'                  => (int) ( $order->qty ?? 0 ),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function snapshotTotals( Order $order ): array
    {
        $product = (float) ( $order->product_amount ?? 0 );
        $delivery = (float) ( $order->delivery_charge ?? 0 );
        $discount = (float) ( $order->sale_discount ?? 0 );
        $paid = (float) ( $order->paid_amount ?? 0 );
        $advance = (float) ( $order->totaladvancepayment ?? 0 );

        return [
            'product_amount'      => $product,
            'delivery_charge'     => $delivery,
            'sale_discount'       => $discount,
            'paid_amount'         => $paid,
            'totaladvancepayment' => $advance,
            'due_amount'          => self::calculateDueAmount( $product, $delivery, $discount, $paid, $advance ),
            'qty'                 => (int) ( $order->qty ?? 0 ),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array{action: string, field: string, old_value: mixed, new_value: mixed, metadata: ?array}
     */
    private function auditDraft(
        string $action,
        string $field,
        mixed $oldValue,
        mixed $newValue,
        ?array $metadata = null
    ): array {
        return [
            'action'    => $action,
            'field'     => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'metadata'  => $metadata,
        ];
    }

    private function stringifyAuditValue( mixed $value ): ?string
    {
        if ( $value === null ) {
            return null;
        }

        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }

        if ( is_array( $value ) ) {
            return json_encode( $value );
        }

        return (string) $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $audits
     */
    private function auditsContainField( array $audits, string $field ): bool
    {
        foreach ( $audits as $audit ) {
            if ( ( $audit['field'] ?? null ) === $field ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $audits
     */
    private function auditsContainAction( array $audits, string $action ): bool
    {
        foreach ( $audits as $audit ) {
            if ( ( $audit['action'] ?? null ) === $action ) {
                return true;
            }
        }

        return false;
    }
}
