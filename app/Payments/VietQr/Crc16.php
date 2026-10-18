<?php

namespace App\Payments\VietQr;

/**
 * CRC-16/CCITT-FALSE (đa thức 0x1021, giá trị đầu 0xFFFF, không đảo bit), theo chuẩn EMVCo QR.
 */
final class Crc16
{
    public static function ccittFalse(string $data): string
    {
        $crc = 0xFFFF;
        $length = strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return sprintf('%04X', $crc);
    }
}
