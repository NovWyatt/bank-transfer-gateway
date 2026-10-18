<?php

namespace Tests\Support;

use App\Webhooks\Security\HostResolver;

/**
 * Bảng tên miền → IP cố định cho test, không gọi DNS thật. Tên không có trong bảng thì không phân giải được.
 */
final class StaticHostResolver implements HostResolver
{
    /** @var list<string> các tên đã được hỏi, theo thứ tự */
    public array $lookups = [];

    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(private array $records = []) {}

    /** @param  list<string>  $ips */
    public function set(string $host, array $ips): void
    {
        $this->records[$host] = $ips;
    }

    public function resolve(string $host): array
    {
        $this->lookups[] = $host;

        return $this->records[$host] ?? [];
    }
}
