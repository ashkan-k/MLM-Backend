<?php

namespace Tests\Unit;

use App\Services\Commission\CommissionCalculator;
use App\Services\Commission\CommissionDistributor;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CommissionMathTest extends TestCase
{
    public function test_shared_representative_splits(): void
    {
        $calc = new CommissionCalculator;
        $this->assertSame('7.500', $calc->sharedPercent('15.000', '50.000'));
        $this->assertSame('6.000', $calc->sharedPercent('15.000', '40.000'));
        $this->assertSame('3.000', $calc->sharedPercent('15.000', '20.000'));
        $this->assertSame('0.800', $calc->sharedPercent('2.000', '40.000'));
        $this->assertSame('10.000', $calc->qualifiedPercent('7.500', '10.000', true));
    }

    public function test_shares_must_total_one_hundred(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CommissionDistributor)->assertShares([
            ['share_percent' => '40.000'],
            ['share_percent' => '40.000'],
        ]);
    }

    public function test_money_never_uses_binary_floats(): void
    {
        $this->assertSame('150000.000', Money::percentOf('1000000', '15.000'));
        $this->assertSame('0', (string) Money::cmp(Money::add('50.000', '50.000'), '100.000'));
    }
}
