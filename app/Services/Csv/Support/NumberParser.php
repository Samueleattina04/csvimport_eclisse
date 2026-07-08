<?php

namespace App\Services\Csv\Support;

/**
 * Il gestionale usa la virgola come separatore decimale (es. "16,10") ovunque,
 * tranne che nel campo LUNGHEZZA dove compare con il punto ("32.00") perche' e'
 * di fatto un'opzione variante testuale, non un prezzo: per quello non si passa
 * mai da qui.
 */
class NumberParser
{
    public static function parseDecimal(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $value);
        if (! is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    public static function parseQuantity(string $value): int
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^-?\d+$/', $value)) {
            return 0;
        }

        return (int) $value;
    }
}
