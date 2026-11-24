<?php

namespace App\Services\Libreria;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class ReceiptMoney
{
    public static function cents(mixed $value): int
    {
        try {
            return BigDecimal::of((string) $value)->multipliedBy(100)->toScale(0, RoundingMode::Unnecessary)->toInt();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['amount' => 'Usa importes válidos con un máximo de dos decimales.']);
        }
    }

    public static function decimal(int $cents): string
    {
        return (string) BigDecimal::of($cents)->dividedBy(100, 2);
    }

    /**
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    public static function distribute(int $amount, array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum === 0) {
            return array_fill_keys(array_keys($weights), 0);
        }
        $parts = $remainders = [];
        foreach ($weights as $key => $weight) {
            [$part, $remainder] = BigInteger::of($amount)->multipliedBy($weight)->quotientAndRemainder($sum);
            $parts[$key] = $part->toInt();
            $remainders[$key] = $remainder->toInt();
        }
        arsort($remainders, SORT_NUMERIC);
        $left = $amount - array_sum($parts);
        foreach (array_keys($remainders) as $key) {
            if ($left-- <= 0) {
                break;
            }
            $parts[$key]++;
        }

        return $parts;
    }
}
