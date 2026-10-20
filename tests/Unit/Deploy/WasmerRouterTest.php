<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/*
| deploy/wasmer/router.php chạy trên PHP built-in server thật, đúng như lệnh run trong wasmer.toml.
| public/ ở đây là bản giả: index.php in ra đường nó nhận được, cộng một file tĩnh.
| Thiếu router, các đường có đuôi file nhận 404 của built-in server (lỗi production 20/10/2026).
*/

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/btg-router-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/public/css', recursive: true);
    file_put_contents($this->root.'/public/index.php', '<?php echo "laravel ", $_SERVER["REQUEST_URI"];');
    file_put_contents($this->root.'/public/css/app.css', 'body{}');
    file_put_contents($this->root.'/.env', 'APP_KEY=bi-mat');

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $this->base = "http://{$address}";

    $this->server = new Process([
        PHP_BINARY, '-S', $address, '-t', $this->root.'/public', dirname(__DIR__, 3).'/deploy/wasmer/router.php',
    ]);
    $this->server->start();

    $deadline = microtime(true) + 10;

    while (($connection = @fsockopen('127.0.0.1', (int) substr($address, strrpos($address, ':') + 1))) === false) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('PHP built-in server không mở cổng: '.$this->server->getErrorOutput());
        }

        usleep(100_000);
    }

    fclose($connection);
});

afterEach(function (): void {
    $this->server->stop();
    (new Filesystem)->deleteDirectory($this->root);
});

function viaRouter(string $base, string $path): string
{
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);

    return (string) file_get_contents($base.$path, context: $context);
}

it('trả thẳng file có thật trong public/', function (): void {
    expect(viaRouter($this->base, '/css/app.css'))->toBe('body{}');
});

it('đưa vào Laravel cả đường có đuôi file mà không có file thật', function (string $path): void {
    expect(viaRouter($this->base, $path))->toBe("laravel {$path}");
})->with([
    'Livewire' => '/livewire-035c1f6d/livewire.min.js?id=8ea5922c',
    'mã QR' => '/qr/DH7K3QXM.svg',
    'OpenAPI' => '/docs/api.json',
    'trang dashboard' => '/app/login',
    'trang chủ' => '/',
]);

it('không trả file nằm ngoài public/', function (): void {
    expect(viaRouter($this->base, '/%2e%2e/.env'))->not->toContain('APP_KEY');
});
