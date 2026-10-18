<?php

use App\Webhooks\Security\UnsafeUrl;
use App\Webhooks\Security\UrlGuard;
use Tests\Support\StaticHostResolver;

beforeEach(function (): void {
    $this->dns = new StaticHostResolver([
        'shop.example' => ['93.184.216.34'],
        'v6.shop.example' => ['2606:4700:4700::1111'],
        'dual.shop.example' => ['2606:4700:4700::1111', '93.184.216.34'],
        'rebind.example' => ['93.184.216.34', '10.0.0.5'],
        'metadata.example' => ['169.254.169.254'],
        'localhost' => ['127.0.0.1'],
    ]);
    $this->guard = new UrlGuard($this->dns);
});

it('cho qua https tới IP công khai và ghim đúng IP cho curl', function (): void {
    $target = $this->guard->check('https://shop.example/hooks/gateway?src=btg#bo-qua');

    expect($target->url)->toBe('https://shop.example/hooks/gateway?src=btg')
        ->and($target->ip)->toBe('93.184.216.34')
        ->and($target->curlOptions())->toBe([CURLOPT_RESOLVE => ['shop.example:443:93.184.216.34']]);
});

it('giữ cổng tự chọn, ưu tiên IPv4 và bọc IPv6 trong ngoặc vuông', function (): void {
    expect($this->guard->check('https://dual.shop.example:8443')->curlOptions())
        ->toBe([CURLOPT_RESOLVE => ['dual.shop.example:8443:93.184.216.34']])
        ->and($this->guard->check('https://v6.shop.example')->curlOptions())
        ->toBe([CURLOPT_RESOLVE => ['v6.shop.example:443:[2606:4700:4700::1111]']]);
});

it('chặn URL không an toàn', function (string $url, string $reason): void {
    expect(fn () => $this->guard->check($url))->toThrow(UnsafeUrl::class, $reason);
})->with([
    'http thường' => ['http://shop.example/hook', 'https://'],
    'loopback' => ['https://127.0.0.1/hook', 'nội bộ'],
    'mạng LAN' => ['https://192.168.1.10/hook', 'nội bộ'],
    'metadata đám mây' => ['https://metadata.example/latest', '169.254.169.254'],
    'IPv6 loopback' => ['https://[::1]/hook', 'nội bộ'],
    'IPv4 nhúng trong IPv6' => ['https://[::ffff:10.0.0.1]/hook', 'nội bộ'],
    'NAT64 về loopback' => ['https://[64:ff9b::7f00:1]/hook', 'nội bộ'],
    'multicast' => ['https://224.0.0.1/hook', 'nội bộ'],
    'một bản ghi DNS trỏ vào LAN' => ['https://rebind.example/hook', '10.0.0.5'],
    'tên một nhãn' => ['https://localhost/hook', 'không hợp lệ'],
    'có tài khoản trong URL' => ['https://user:pass@shop.example/hook', 'mật khẩu'],
    'dấu gạch ngược' => ['https://shop.example\@127.0.0.1/', 'ký tự không hợp lệ'],
    'dấu cách' => ['https://shop.example/a b', 'ký tự không hợp lệ'],
    'tên miền có dấu' => ['https://cửahàng.vn/hook', 'ký tự không hợp lệ'],
    'không phân giải được' => ['https://khong-ton-tai.example/hook', 'Không phân giải được'],
]);

it('phân giải lại ở mỗi lần kiểm: DNS đổi sang IP nội bộ thì lần sau bị chặn', function (): void {
    $this->guard->check('https://shop.example/hook');

    $this->dns->set('shop.example', ['127.0.0.1']);

    expect(fn () => $this->guard->check('https://shop.example/hook'))->toThrow(UnsafeUrl::class);
    expect($this->dns->lookups)->toBe(['shop.example', 'shop.example']);
});

it('máy dev bật allow_private thì cho http và localhost để thử với script mẫu', function (): void {
    $guard = new UrlGuard($this->dns, allowPrivate: true);

    expect($guard->check('http://localhost:9000/')->curlOptions())
        ->toBe([CURLOPT_RESOLVE => ['localhost:9000:127.0.0.1']])
        ->and($guard->check('http://127.0.0.1:9000/')->curlOptions())->toBe([]);
});

it('xếp loại IP công khai', function (string $ip, bool $public): void {
    expect(UrlGuard::isPublicIp($ip))->toBe($public);
})->with([
    ['8.8.8.8', true],
    ['2606:4700:4700::1111', true],
    ['64:ff9b::808:808', true],
    ['100.64.0.1', false],
    ['0.0.0.0', false],
    ['fd12::1', false],
    ['fe80::1', false],
    ['ff02::1', false],
    ['2001:db8::1', false],
]);
