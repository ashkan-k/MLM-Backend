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
}
