<?php

/**
 * Kiểm chữ ký webhook của Bank Transfer Gateway. PHP 7.4 trở lên, không cần thư viện ngoài.
 *
 * Header: X-Gateway-Signature: t=1760752800,v1=5257a869…
 * Chữ ký: hex(hmac_sha256(secret, "{t}.{body gốc}")), khóa là nguyên chuỗi secret (kể cả "whsec_").
 *
 * @param  string  $payload  body gốc: file_get_contents('php://input'). Đừng json_decode rồi encode lại,
 *                           vì chỉ cần lệch một dấu cách là chữ ký sai.
 * @param  string  $header  giá trị header X-Gateway-Signature
 * @param  string  $secret  secret của endpoint, hiện một lần trên dashboard lúc tạo
 * @param  int  $toleranceSeconds  lệch giờ tối đa; quá thì coi là bản phát lại cũ
 */
function gateway_verify_signature(string $payload, string $header, string $secret, int $toleranceSeconds = 300, ?int $now = null): bool
{
    $timestamp = null;
    $signatures = [];

    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

        if ($key === 't' && ctype_digit($value)) {
            $timestamp = (int) $value;
        } elseif ($key === 'v1') {
            // Có thể có nhiều v1 (ví dụ lúc đổi secret): đúng một cái là đủ.
            $signatures[] = $value;
        }
    }

    if ($timestamp === null || $signatures === []) {
        return false;
    }

    if (abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

    foreach ($signatures as $signature) {
        // hash_equals so sánh hằng thời gian: không lộ chữ ký qua thời gian phản hồi.
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }

    return false;
}
