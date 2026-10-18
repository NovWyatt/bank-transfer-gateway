<?php

namespace App\Payments\VietQr;

use InvalidArgumentException;

/**
 * Dựng chuỗi VietQR (EMVCo/NAPAS) cho chuyển khoản tới một tài khoản, không gọi API ngoài.
 *
 * Cấu trúc: 00 phiên bản, 01 kiểu QR (11 tĩnh, 12 động có số tiền), 38 thông tin người nhận
 * (00 AID NAPAS, 01 {00 BIN, 01 số tài khoản}, 02 dịch vụ QRIBFTTA), 53 tiền tệ 704, 54 số tiền,
 * 58 quốc gia, 62 {08 nội dung}, 63 CRC tính trên toàn chuỗi kể cả "6304".
 */
final class VietQrPayload
{
    private const NAPAS_AID = 'A000000727';

    private const SERVICE_ACCOUNT_TRANSFER = 'QRIBFTTA';

    public static function build(string $bankBin, string $accountNumber, ?int $amount = null, string $memo = ''): string
    {
        if (preg_match('/^\d{6}$/', $bankBin) !== 1) {
            throw new InvalidArgumentException('BIN ngân hàng phải gồm 6 chữ số.');
        }

        if (preg_match('/^[0-9A-Za-z]{1,19}$/', $accountNumber) !== 1) {
            throw new InvalidArgumentException('Số tài khoản chỉ gồm chữ và số, tối đa 19 ký tự.');
        }

        if ($amount !== null && $amount <= 0) {
            throw new InvalidArgumentException('Số tiền phải là số nguyên VND dương.');
        }

        // App ngân hàng xử lý nội dung không dấu ổn định nhất.
        if ($memo !== '' && preg_match('/^[A-Z0-9 ]{1,25}$/', $memo) !== 1) {
            throw new InvalidArgumentException('Nội dung chỉ gồm chữ in hoa không dấu, số và dấu cách, tối đa 25 ký tự.');
        }

        $beneficiary = self::field('00', self::NAPAS_AID)
            .self::field('01', self::field('00', $bankBin).self::field('01', $accountNumber))
            .self::field('02', self::SERVICE_ACCOUNT_TRANSFER);

        $payload = self::field('00', '01')
            .self::field('01', $amount === null ? '11' : '12')
            .self::field('38', $beneficiary)
            .self::field('53', '704')
            .($amount === null ? '' : self::field('54', (string) $amount))
            .self::field('58', 'VN')
            .($memo === '' ? '' : self::field('62', self::field('08', $memo)))
            .'6304';

        return $payload.Crc16::ccittFalse($payload);
    }

    /** Một trường TLV: mã 2 ký tự, độ dài 2 chữ số, giá trị. */
    private static function field(string $id, string $value): string
    {
        $length = strlen($value);

        if ($length > 99) {
            throw new InvalidArgumentException("Trường {$id} dài quá 99 ký tự.");
        }

        return $id.str_pad((string) $length, 2, '0', STR_PAD_LEFT).$value;
    }
}
