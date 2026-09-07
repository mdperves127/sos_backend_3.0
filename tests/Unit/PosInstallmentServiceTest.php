<?php

namespace Tests\Unit;

use App\Service\Vendor\PosInstallmentService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosInstallmentServiceTest extends TestCase
{
    public function test_equal_installment_amounts_are_valid(): void
    {
        $schedule = PosInstallmentService::validateSchedule( [
            ['installment_number' => 1, 'amount' => 10000, 'due_date' => '2026-09-10'],
            ['installment_number' => 2, 'amount' => 10000, 'due_date' => '2026-10-10'],
            ['installment_number' => 3, 'amount' => 10000, 'due_date' => '2026-11-10'],
        ], 30000 );

        $this->assertCount( 3, $schedule );
        $this->assertSame( 10000.0, $schedule[0]['amount'] );
    }

    public function test_unequal_installment_amounts_are_valid(): void
    {
        $schedule = PosInstallmentService::validateSchedule( [
            ['amount' => 5000, 'due_date' => '2026-09-10'],
            ['amount' => 10000, 'due_date' => '2026-10-10'],
            ['amount' => 15000, 'due_date' => '2026-11-10'],
        ], 30000 );

        $this->assertCount( 3, $schedule );
    }

    public function test_invalid_installment_total_is_rejected(): void
    {
        $this->expectException( ValidationException::class );

        PosInstallmentService::validateSchedule( [
            ['amount' => 10000, 'due_date' => '2026-09-10'],
            ['amount' => 10000, 'due_date' => '2026-10-10'],
        ], 30000 );
    }

    public function test_zero_amount_installment_is_rejected(): void
    {
        $this->expectException( ValidationException::class );

        PosInstallmentService::validateSchedule( [
            ['amount' => 0, 'due_date' => '2026-09-10'],
            ['amount' => 30000, 'due_date' => '2026-10-10'],
        ], 30000 );
    }
}
