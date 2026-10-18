<?php

namespace App\Webhooks\Security;

/**
 * Đích đã kiểm tra: gửi tới $url và ép curl kết nối đúng $ip.
 */
final readonly class Target
{
    public function __construct(
        /** URL dựng lại từ các phần đã parse, để curl không hiểu host khác với lúc kiểm tra. */
        public string $url,
        /** "https", hoặc "http" khi máy dev bật allow_private_targets. */
        public string $scheme,
        public string $host,
        public int $port,
        public string $ip,
    ) {}

    /**
     * Ghim IP đã kiểm tra: curl không tự phân giải lại, nên đổi DNS giữa lúc kiểm và lúc gửi
     * (DNS rebinding) không có tác dụng.
     *
     * @return array<int, mixed>
     */
    public function curlOptions(): array
    {
        // URL ghi thẳng IP thì curl không phân giải gì, không cần ghim.
        if (filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        $address = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        return [CURLOPT_RESOLVE => ["{$this->host}:{$this->port}:{$address}"]];
    }
}
