<?php

namespace App\Webhooks\Security;

/**
 * Phân giải tên miền ra địa chỉ IP. Tách thành interface để test không gọi DNS thật.
 */
interface HostResolver
{
    /** @return list<string> mọi địa chỉ IPv4 và IPv6 của $host; rỗng khi không phân giải được */
    public function resolve(string $host): array;
}
