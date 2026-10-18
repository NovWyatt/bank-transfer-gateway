<?php

namespace App\Payments;

use InvalidArgumentException;

/**
 * Mã đơn: tiền tố của shop (2–4 chữ cái) + 6 ký tự ngẫu nhiên, ví dụ DH7K3QXM.
 *
 * Không có gạch hay dấu, vì nhiều ngân hàng xóa ký tự đặc biệt, đổi chữ hoa hoặc cắt nội dung.
 */
final class OrderCode
{
    /** Bỏ 0 1 I L O U vì dễ đọc nhầm. 30 ký tự, nên có 30^6 ≈ 729 triệu tổ hợp. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const BODY_LENGTH = 6;

    public const DEFAULT_PREFIX = 'DH';

    public static function generate(string $prefix = self::DEFAULT_PREFIX): string
    {
        self::assertValidPrefix($prefix);

        $max = strlen(self::ALPHABET) - 1;
        $body = '';

        for ($i = 0; $i < self::BODY_LENGTH; $i++) {
            $body .= self::ALPHABET[random_int(0, $max)];
        }

        return $prefix.$body;
    }

    public static function isValidPrefix(string $prefix): bool
    {
        return preg_match('/^[A-Z]{2,4}$/', $prefix) === 1;
    }

    public static function assertValidPrefix(string $prefix): void
    {
        if (! self::isValidPrefix($prefix)) {
            throw new InvalidArgumentException("Tiền tố mã đơn phải là 2–4 chữ cái in hoa, nhận được [{$prefix}].");
        }
    }

    /** Biểu thức chính quy (không có dấu phân cách) khớp một mã đầy đủ với tiền tố cho trước. */
    public static function pattern(string $prefix): string
    {
        self::assertValidPrefix($prefix);

        return $prefix.'['.self::ALPHABET.']{'.self::BODY_LENGTH.'}';
    }

    public static function isWellFormed(string $code, string $prefix): bool
    {
        return preg_match('/^'.self::pattern($prefix).'$/', $code) === 1;
    }
}
