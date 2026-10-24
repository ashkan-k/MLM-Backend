<?php

namespace Tests\Unit;

use App\Support\JalaliDate;
use PHPUnit\Framework\TestCase;

class JalaliDateTest extends TestCase
{
    public function test_nowruz_2024_is_1403_01_01(): void
    {
        $this->assertSame('1403/1/1', JalaliDate::format('2024-03-20'));
    }

    public function test_jalali_input_is_kept(): void
    {
        $this->assertSame('1370/01/15', JalaliDate::format('1370/01/15'));
    }

    public function test_store_keeps_jalali_and_converts_gregorian(): void
    {
        $this->assertSame('1368-04-26', JalaliDate::store('1368/04/26'));
        $this->assertSame('1368-04-26', JalaliDate::store('۱۳۶۸/۰۴/۲۶'));
        $this->assertSame('1403-01-01', JalaliDate::store('2024-03-20'));
        $this->assertSame('1370/01/15', JalaliDate::format('1370-01-15'));
        $this->assertNull(JalaliDate::store(''));
        $this->assertNull(JalaliDate::store('not-a-date'));
    }

    public function test_business_month_switches_to_jalali_from_aban(): void
    {
        $mehr = \Carbon\Carbon::parse('2026-10-21 12:00:00', JalaliDate::TIMEZONE);
        $this->assertSame('2026-10', JalaliDate::businessMonthKey($mehr));

        $aban = \Carbon\Carbon::parse('2026-10-24 12:00:00', JalaliDate::TIMEZONE);
        $this->assertSame('1405-08', JalaliDate::businessMonthKey($aban));

        [$start] = JalaliDate::businessMonthBounds($aban, '1405-08', true);
        $this->assertTrue($start->equalTo(JalaliDate::bonusJalaliProfitCutover()));
    }
}
