<?php

namespace Tests\Unit;

use App\Services\PlatformFeeService;
use Tests\TestCase;

class PlatformFeeServiceTest extends TestCase
{
    private function feeService( string $type, float $rate, string $source = 'global' ): string
    {
        $service = new class extends PlatformFeeService {
            public static array $rule = ['none', 0.0, 'global'];

            protected static function resolveRule( string|int|null $dropshipperTenantId ): array
            {
                return static::$rule;
            }
        };

        $service::$rule = [$type, $rate, $source];

        return $service::class;
    }

    public function test_percentage_fee_is_taken_from_gross(): void
    {
        $fee = $this->feeService( 'percentage', 10 )::calculate( 'ds1', 250 );

        $this->assertSame( 25.0, $fee['amount'] );
        $this->assertSame( 225.0, $fee['net'] );
        $this->assertSame( 'percentage', $fee['type'] );
    }

    public function test_fixed_fee_never_exceeds_gross(): void
    {
        $fee = $this->feeService( 'fixed', 50 )::calculate( 'ds1', 30 );

        $this->assertSame( 30.0, $fee['amount'] );
        $this->assertSame( 0.0, $fee['net'] );
    }

    public function test_no_fee_mode_and_zero_gross(): void
    {
        $this->assertSame( 0.0, $this->feeService( 'none', 0 )::calculate( 'ds1', 500 )['amount'] );
        $this->assertSame( 0.0, $this->feeService( 'percentage', 10 )::calculate( 'ds1', 0 )['amount'] );
    }

    public function test_plan_fee_source_is_recorded(): void
    {
        $fee = $this->feeService( 'percentage', 5, 'plan:7' )::calculate( 'ds1', 1000 );

        $this->assertSame( 50.0, $fee['amount'] );
        $this->assertSame( 'plan:7', $fee['source'] );
    }
}
