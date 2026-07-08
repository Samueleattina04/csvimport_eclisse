<?php

namespace Tests\Unit\Csv;

use App\Services\Csv\Support\NumberParser;
use PHPUnit\Framework\TestCase;

class NumberParserTest extends TestCase
{
    public function test_parse_decimal_converte_la_virgola_in_punto(): void
    {
        $this->assertSame('16.10', NumberParser::parseDecimal('16,10'));
        $this->assertSame('119.00', NumberParser::parseDecimal('119,00'));
    }

    public function test_parse_decimal_gestisce_valori_gia_con_il_punto(): void
    {
        $this->assertSame('32.00', NumberParser::parseDecimal('32.00'));
    }

    public function test_parse_decimal_restituisce_null_per_valori_vuoti_o_non_numerici(): void
    {
        $this->assertNull(NumberParser::parseDecimal(''));
        $this->assertNull(NumberParser::parseDecimal('   '));
        $this->assertNull(NumberParser::parseDecimal('n/d'));
    }

    public function test_parse_quantity_gestisce_valori_negativi(): void
    {
        $this->assertSame(-3, NumberParser::parseQuantity('-3'));
        $this->assertSame(0, NumberParser::parseQuantity('0'));
        $this->assertSame(5, NumberParser::parseQuantity('5'));
    }

    public function test_parse_quantity_restituisce_zero_per_valori_non_validi(): void
    {
        $this->assertSame(0, NumberParser::parseQuantity(''));
        $this->assertSame(0, NumberParser::parseQuantity('n/d'));
    }
}
