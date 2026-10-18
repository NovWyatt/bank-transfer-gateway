<?php

namespace App\Internal;

/**
 * Chữ ký của request máy gọi máy tới /internal/*:
 * X-Internal-Signature: t={unix},v1={hex(hmac_sha256(secret, "{t}.{METHOD} {path}\n{body}"))}
 *
 * Ký cả method và đường dẫn: chữ ký của /internal/tick không dùng được cho /internal/deploy.
 * Secret đi trong header, không trong URL, vì log truy cập của Wasmer ghi nguyên URL.
 * Bên ký: edge/src/index.js (Worker) và scripts/internal-call.sh (CI).
 */
final class InternalSignature
{
    public const HEADER = 'X-Internal-Signature';

    public const TOLERANCE_SECONDS = 300;

    public static function header(string $secret, string $method, string $path, string $body, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.self::compute($secret, $method, $path, $body, $timestamp);
    }

    public static function verify(string $header, string $secret, string $method, string $path, string $body, int $now): bool
    {
        if (preg_match('/^t=(\d{1,12}),v1=([a-f0-9]{64})$/D', $header, $match) !== 1) {
            return false;
        }

        $timestamp = (int) $match[1];

        return abs($now - $timestamp) <= self::TOLERANCE_SECONDS
            && hash_equals(self::compute($secret, $method, $path, $body, $timestamp), $match[2]);
    }

    private static function compute(string $secret, string $method, string $path, string $body, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.strtoupper($method).' '.$path."\n".$body, $secret);
    }
}
