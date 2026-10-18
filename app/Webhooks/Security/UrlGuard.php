<?php

namespace App\Webhooks\Security;

/**
 * Chặn SSRF cho webhook gửi đi: chỉ gửi tới https trên địa chỉ IP công khai.
 *
 * Gọi lúc lưu URL (báo lỗi sớm cho owner) và lại ở mỗi lần gửi (DNS có thể đã đổi).
 */
final class UrlGuard
{
    /** Một hoặc nhiều nhãn chữ thường, số, gạch ngang, ngăn bằng dấu chấm; nhãn cuối là chữ. */
    private const HOSTNAME = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    /** 64:ff9b::/96: NAT64 nhúng địa chỉ IPv4 ở 4 byte cuối. */
    private const NAT64_PREFIX = "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00";

    public function __construct(
        private readonly HostResolver $resolver,
        /** Chỉ máy dev: cho http:// và địa chỉ nội bộ để thử với script mẫu ở localhost. */
        private readonly bool $allowPrivate = false,
    ) {}

    /**
     * @throws UnsafeUrl
     */
    public function check(string $url): Target
    {
        // Chỉ ký tự ASCII in được và không có "\": curl và parse_url hiểu "\" khác nhau.
        if (preg_match('/^[\x21-\x7E]+$/', $url) !== 1 || str_contains($url, '\\')) {
            throw new UnsafeUrl('URL chứa ký tự không hợp lệ (dấu cách, dấu "\" hoặc ký tự có dấu).');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new UnsafeUrl('URL không hợp lệ.');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'https' && ! ($this->allowPrivate && $scheme === 'http')) {
            throw new UnsafeUrl('URL phải bắt đầu bằng https://.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrl('URL không được chứa tên đăng nhập hay mật khẩu.');
        }

        $host = strtolower($parts['host']);
        $literal = trim($host, '[]');
        $isIp = filter_var($literal, FILTER_VALIDATE_IP) !== false;

        if (! $isIp && preg_match(self::HOSTNAME, $host) !== 1 && ! ($this->allowPrivate && $host === 'localhost')) {
            throw new UnsafeUrl("Tên miền \"{$host}\" không hợp lệ. Tên miền tiếng Việt có dấu thì nhập dạng xn--.");
        }

        $ips = $isIp ? [$literal] : $this->resolver->resolve($host);

        if ($ips === []) {
            throw new UnsafeUrl("Không phân giải được tên miền {$host}.");
        }

        // Xét mọi địa chỉ: chỉ cần một bản ghi trỏ vào mạng nội bộ là chặn.
        foreach ($ips as $ip) {
            if (! $this->allowPrivate && ! self::isPublicIp($ip)) {
                throw new UnsafeUrl("{$host} trỏ tới địa chỉ nội bộ hoặc dành riêng ({$ip}), không gửi được.");
            }
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $authority = $host.(isset($parts['port']) ? ':'.$port : '');
        $rebuilt = $scheme.'://'.$authority.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        return new Target($rebuilt, $scheme, $literal, $port, self::preferIpv4($ips));
    }

    /**
     * Địa chỉ đi được ra Internet công khai.
     *
     * FILTER_FLAG_GLOBAL_RANGE đã chặn private, loopback, link-local (gồm metadata 169.254.169.254),
     * CGNAT và IPv4 nhúng trong IPv6 (::ffff:a.b.c.d), nhưng vẫn cho qua multicast và NAT64.
     */
    public static function isPublicIp(string $ip): bool
    {
        // Hợp lệ thì filter_var trả lại nguyên chuỗi. So với $ip thay vì với false: PHPStan 2.3
        // suy sai rằng có FILTER_FLAG_GLOBAL_RANGE thì không bao giờ trả false.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== $ip) {
            return false;
        }

        $packed = (string) inet_pton($ip);

        if (strlen($packed) === 4) {
            return ord($packed[0]) < 224;
        }

        if (ord($packed[0]) === 0xFF) {
            return false;
        }

        if (str_starts_with($packed, self::NAT64_PREFIX)) {
            return self::isPublicIp((string) inet_ntop(substr($packed, 12)));
        }

        return true;
    }

    /** @param  non-empty-list<string>  $ips */
    private static function preferIpv4(array $ips): string
    {
        foreach ($ips as $ip) {
            if (! str_contains($ip, ':')) {
                return $ip;
            }
        }

        return $ips[0];
    }
}
