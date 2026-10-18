<?php

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
