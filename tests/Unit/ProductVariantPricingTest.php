<?php

namespace Tests\Unit;

use App\Service\Vendor\ProductVariantService;
use Tests\TestCase;

class ProductVariantPricingTest extends TestCase
{
    public function test_product_fallback_prefers_discount_price(): void
    {
        $product = (object) [
            'selling_price'  => 1000,
            'discount_price' => 800,
        ];

        $this->assertSame( 800.0, ProductVariantService::productFallbackPrice( $product ) );
    }

    public function test_product_fallback_uses_selling_price_when_no_discount(): void
    {
        $product = (object) [
            'selling_price'  => 1000,
            'discount_price' => null,
        ];

        $this->assertSame( 1000.0, ProductVariantService::productFallbackPrice( $product ) );
    }

    public function test_resolve_rate_from_payload_accepts_price_or_rate(): void
    {
        $this->assertSame( 250.5, ProductVariantService::resolveRateFromPayload( ['price' => 250.5] ) );
        $this->assertSame( 120.0, ProductVariantService::resolveRateFromPayload( ['rate' => 120] ) );
        $this->assertSame( 0.0, ProductVariantService::resolveRateFromPayload( [] ) );
        $this->assertSame( 0.0, ProductVariantService::resolveRateFromPayload( ['rate' => -5] ) );
    }

    public function test_variant_unit_price_uses_rate_when_positive(): void
    {
        $product = (object) ['selling_price' => 1000, 'discount_price' => null];
        $variant = (object) ['rate' => 750];

        $this->assertSame( 750.0, ProductVariantService::resolveVariantUnitPrice( $variant, $product ) );
    }

    public function test_variant_unit_price_falls_back_when_rate_is_zero(): void
    {
        $product = (object) ['selling_price' => 1000, 'discount_price' => 900];
        $variant = (object) ['rate' => 0];

        $this->assertSame( 900.0, ProductVariantService::resolveVariantUnitPrice( $variant, $product ) );
        $this->assertSame( 900.0, ProductVariantService::resolveVariantUnitPrice( null, $product ) );
    }

    public function test_line_totals_use_variant_rates_with_weighted_unit_price(): void
    {
        $product = (object) [
            'id'             => 1,
            'selling_price'  => 1000,
            'discount_price' => null,
        ];

        // Avoid DB: empty cart items with no variant_ids uses fallback only when no items
        $empty = ProductVariantService::lineTotalsFromCartItems( $product, [] );
        $this->assertSame( 1000.0, $empty['product_price'] );
        $this->assertSame( 0.0, $empty['total_product_price'] );

        // Items without variant_id should use product fallback without DB lookup
        $totals = ProductVariantService::lineTotalsFromCartItems( $product, [
            ['qty' => 2],
            ['qty' => 1],
        ] );

        $this->assertSame( 1000.0, $totals['product_price'] );
        $this->assertSame( 3000.0, $totals['total_product_price'] );
        $this->assertSame( 3, $totals['total_qty'] );
    }
}
