<?php

namespace App\Webhooks;

/**
 * Chữ ký webhook gửi đi, kiểu Stripe:
 * X-Gateway-Signature: t={unix},v1={hex(hmac_sha256(secret, "{t}.{raw_body}"))}
 *
 * Phía shop kiểm bằng script mẫu trong examples/webhooks. Khóa HMAC là nguyên chuỗi secret,
 * kể cả tiền tố whsec_. t đổi theo từng lần gửi, nên phía shop từ chối được bản phát lại cũ.
 */
final class WebhookSignature
{
    public const HEADER = 'X-Gateway-Signature';

    public static function header(string $payload, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
