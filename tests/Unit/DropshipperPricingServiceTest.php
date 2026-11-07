<?php

namespace Tests\Unit;

use App\Services\DropshipperPricingService;
use Tests\TestCase;

class DropshipperPricingServiceTest extends TestCase
{
    private function product( array $overrides = [] ): object
    {
        return (object) array_merge( [
            'selling_price'              => '1000',
            'discount_price'             => null,
            'allow_custom_selling_price' => 1,
            'dropshipper_price_mode'     => 'range',
            'dropshipper_min_price'      => null,
            'dropshipper_max_price'      => null,
            'dropshipper_fixed_price'    => null,
        ], $overrides );
    }

    public function test_base_price_prefers_discount_price(): void
    {
        $this->assertSame( 1000.0, DropshipperPricingService::basePrice( $this->product() ) );
        $this->assertSame( 900.0, DropshipperPricingService::basePrice( $this->product( ['discount_price' => '900'] ) ) );
    }

    public function test_rejects_malformed_negative_and_zero_prices(): void
    {
        foreach ( ['-10', '0', 'abc', '10.999', '', '1e3', null] as $value ) {
            $result = DropshipperPricingService::validateSellingPrice( $this->product(), $value );
            $this->assertFalse( $result['ok'], 'Expected rejection for ' . var_export( $value, true ) );
        }
    }

    public function test_price_must_respect_merchant_price_and_range(): void
    {
        $product = $this->product( ['dropshipper_min_price' => '1100', 'dropshipper_max_price' => '1500'] );

        $this->assertFalse( DropshipperPricingService::validateSellingPrice( $product, '999.99' )['ok'] );
        $this->assertFalse( DropshipperPricingService::validateSellingPrice( $product, '1099.99' )['ok'] );
        $this->assertFalse( DropshipperPricingService::validateSellingPrice( $product, '1500.01' )['ok'] );

        $ok = DropshipperPricingService::validateSellingPrice( $product, '1250.50' );
        $this->assertTrue( $ok['ok'] );
        $this->assertSame( 1250.5, $ok['price'] );
    }

    public function test_fixed_mode_and_custom_off_enforce_a_single_price(): void
    {
        $fixed = $this->product( ['dropshipper_price_mode' => 'fixed', 'dropshipper_fixed_price' => '1200'] );
        $this->assertTrue( DropshipperPricingService::validateSellingPrice( $fixed, '1200' )['ok'] );
        $this->assertFalse( DropshipperPricingService::validateSellingPrice( $fixed, '1300' )['ok'] );

        $off = $this->product( ['allow_custom_selling_price' => 0] );
        $this->assertTrue( DropshipperPricingService::validateSellingPrice( $off, '1000' )['ok'] );
        $this->assertFalse( DropshipperPricingService::validateSellingPrice( $off, '1100' )['ok'] );
    }

    public function test_effective_price_is_reclamped_and_never_changes_base_price(): void
    {
        $product = $this->product( ['dropshipper_max_price' => '1300'] );

        // Stored price above the merchant's new maximum is clamped.
        $detail = (object) ['selling_price' => '1400', 'profit_amount' => 400];
        $this->assertSame( 1300.0, DropshipperPricingService::effectiveSellingPrice( $product, $detail ) );
        $this->assertSame( 300.0, DropshipperPricingService::unitMargin( $product, $detail ) );

        // Legacy rows without selling_price keep using profit_amount as the margin.
        $legacy = (object) ['selling_price' => null, 'profit_amount' => 150];
        $this->assertSame( 150.0, DropshipperPricingService::unitMargin( $this->product(), $legacy ) );

        $this->assertSame( '1000', $product->selling_price );
    }
}
