<?php

namespace App\Webhooks\Security;

/**
 * Phân giải bằng resolver của hệ điều hành.
 *
 * gethostbynamel chỉ trả IPv4; dns_get_record lấy thêm AAAA khi có hàm này
 * (bản PHP WebAssembly trên Wasmer có thể thiếu, khi đó chỉ xét IPv4).
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $ips = @gethostbynamel($host) ?: [];

        if (function_exists('dns_get_record')) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
