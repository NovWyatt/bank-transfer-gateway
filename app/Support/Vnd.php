<?php

namespace App\Support;

final class Vnd
{
    /** 150000 → "150.000đ" */
    public static function format(int $amount): string
    {
        return number_format($amount, 0, ',', '.').'đ';
    }
}
