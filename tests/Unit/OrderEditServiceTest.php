<?php

namespace Tests\Unit;

use App\Models\OrderEditHistory;
use App\Services\OrderEditService;
use Tests\TestCase;

class OrderEditServiceTest extends TestCase
{
    public function test_due_amount_follows_existing_merchant_formula(): void
    {
        // product + delivery - discount - paid - advance
        $this->assertSame(
            960.0,
            OrderEditService::calculateDueAmount( 1000, 60, 100, 0, 0 )
        );

        $this->assertSame(
            860.0,
            OrderEditService::calculateDueAmount( 1000, 60, 100, 100, 0 )
        );

        $this->assertSame(
            800.0,
            OrderEditService::calculateDueAmount( 1000, 60, 100, 100, 60 )
        );
    }

    public function test_due_amount_never_goes_negative(): void
    {
        $this->assertSame(
            0.0,
            OrderEditService::calculateDueAmount( 100, 0, 200, 0, 0 )
        );
    }

    public function test_locked_statuses_block_editing(): void
    {
        $service = new OrderEditService();

        foreach ( ['cancel', 'delivered', 'return', 'courier'] as $status ) {
            $order = new \App\Models\Order( ['status' => $status] );
            $this->assertNotNull( $service->editLockReason( $order ), $status );
        }

        foreach ( ['pending', 'hold', 'received', 'processing', 'ready', 'progress'] as $status ) {
            $order = new \App\Models\Order( ['status' => $status] );
            $this->assertNull( $service->editLockReason( $order ), $status );
        }
    }

    public function test_audit_history_is_immutable(): void
    {
        $history = new OrderEditHistory( [
            'order_id' => 1,
            'action'   => 'discount_changed',
        ] );

        $this->expectException( \RuntimeException::class );
        $history->delete();
    }
}
