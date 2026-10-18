<?php

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/*
| Test Feature chạy trên MySQL/MariaDB thật (xem phpunit.xml), vì khớp đơn
| dựa vào lockForUpdate và ràng buộc UNIQUE mà SQLite không mô phỏng đúng.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** Nội dung nguyên văn của một file trong tests/Fixtures (fixture() của Pest chỉ trả đường dẫn). */
function fixtureContent(string $path): string
{
    return (string) file_get_contents(fixture($path));
}

/**
 * Request POST giống webhook thật: body gốc giữ nguyên byte, header đặt đúng tên.
 *
 * @param  array<string, string>  $headers
 */
function webhookRequest(string $body, array $headers = [], string $contentType = 'application/json'): Request
{
    $server = ['CONTENT_TYPE' => $contentType];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create('/hooks/test', 'POST', server: $server, content: $body);
}

/**
 * Tạo token thật cho shop và gắn vào các request sau. Quên guard cũ trước, vì trong một test
 * app giữ nguyên instance và RequestGuard nhớ shop của request trước.
 *
 * @param  list<string>  $abilities
 */
function apiAs(Shop $shop, array $abilities = ['orders:read', 'orders:write', 'transactions:read', 'webhooks:write']): string
{
    app('auth')->forgetGuards();

    $token = $shop->createToken('test', $abilities)->plainTextToken;
    test()->withToken($token);

    return $token;
}
