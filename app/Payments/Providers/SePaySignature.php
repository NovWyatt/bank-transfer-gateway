<?php

namespace App\Payments\Providers;

use App\Payments\Providers\Exceptions\InvalidSignature;

/**
 * Chữ ký HMAC kiểu SePay: sha256={hex(hmac_sha256(secret, "{timestamp}.{raw_body}"))}.
 * Nguồn: https://developer.sepay.vn/en/sepay-webhooks/xac-thuc
 */
final class SePaySignature
{
    public const SIGNATURE_HEADER = 'X-SePay-Signature';

    public const TIMESTAMP_HEADER = 'X-SePay-Timestamp';

    /** Lệch quá 5 phút so với giờ máy chủ thì từ chối, chống gửi lại request cũ. */
    public const TOLERANCE_SECONDS = 300;

    public static function sign(string $body, string $secret, int $timestamp): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Header để gửi kèm body, dùng cho bộ giả lập và test.
     *
     * @return array<string, string>
     */
    public static function headers(string $body, string $secret, int $timestamp): array
    {
        return [
            self::SIGNATURE_HEADER => self::sign($body, $secret, $timestamp),
            self::TIMESTAMP_HEADER => (string) $timestamp,
        ];
    }

    /**
     * @throws InvalidSignature
     */
    public static function verify(string $body, string $secret, ?string $signature, ?string $timestamp, int $now): void
    {
        if ($signature === null || $signature === '' || $timestamp === null || ! ctype_digit($timestamp)) {
            throw new InvalidSignature('Thiếu chữ ký hoặc timestamp.');
        }

        if (abs($now - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            throw new InvalidSignature('Timestamp lệch quá '.self::TOLERANCE_SECONDS.' giây.');
        }

        if (! hash_equals(self::sign($body, $secret, (int) $timestamp), $signature)) {
            throw new InvalidSignature('Chữ ký không khớp.');
        }
    }
}
