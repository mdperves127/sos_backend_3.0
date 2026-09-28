<?php

namespace Tests\Unit;

use App\Services\ProductPreOrderService;
use Tests\TestCase;

class ProductPreOrderServiceTest extends TestCase
{
    public function test_lifecycle_mapping(): void
    {
        $this->assertSame( 'confirmed', ProductPreOrderService::lifecycleFromOrderStatus( 'pending' ) );
        $this->assertSame( 'processing', ProductPreOrderService::lifecycleFromOrderStatus( 'processing' ) );
        $this->assertSame( 'ready', ProductPreOrderService::lifecycleFromOrderStatus( 'ready' ) );
        $this->assertSame( 'completed', ProductPreOrderService::lifecycleFromOrderStatus( 'delivered' ) );
        $this->assertSame( 'cancelled', ProductPreOrderService::lifecycleFromOrderStatus( 'cancel' ) );
    }

    public function test_is_pre_order_product(): void
    {
        $on  = (object) ['pre_order' => '1'];
        $off = (object) ['pre_order' => '0'];

        $this->assertTrue( ProductPreOrderService::isPreOrderProduct( $on ) );
        $this->assertFalse( ProductPreOrderService::isPreOrderProduct( $off ) );
    }
}
