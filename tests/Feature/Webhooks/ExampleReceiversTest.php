<?php

use App\Enums\DeliveryTrigger;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Models\Order;
use App\Models\WebhookAttempt;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\DeliverDueWebhooks;
use App\Webhooks\Security\HostResolver;
use App\Webhooks\WebhookDispatcher;
use App\Webhooks\WebhookSender;
use App\Webhooks\WebhookSignature;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Tests\Support\StaticHostResolver;

/*
| Script mẫu trong examples/webhooks chạy thật như máy chủ HTTP ở localhost, gateway gửi webhook thật tới
| (không Http::fake), đi qua đường ghim IP bằng CURLOPT_RESOLVE. Đây là tiêu chí "xong" của GĐ2.
*/

/** Cổng TCP còn trống ở 127.0.0.1. */
function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr($name, strrpos($name, ':') + 1);
}

/** @param  list<string>  $command */
function startReceiver(array $command, string $directory, int $port, string $secret): InvokedProcess
{
    $process = Process::path($directory)
        ->env(['GATEWAY_WEBHOOK_SECRET' => $secret, 'PORT' => (string) $port])
        ->start($command);

    $deadline = microtime(true) + 10;

    while (microtime(true) < $deadline) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);

        if ($connection !== false) {
            fclose($connection);

            return $process;
        }

        usleep(100_000);
    }

    $process->stop();

    throw new RuntimeException("Script mẫu không mở cổng {$port}: ".$process->latestErrorOutput());
}

function nodeAvailable(): bool
{
    return Process::run(['node', '--version'])->successful();
}

beforeEach(function (): void {
    // "localhost" đi qua bộ phân giải và được ghim IP, như một tên miền thật.
    app()->instance(HostResolver::class, new StaticHostResolver(['localhost' => ['127.0.0.1']]));
    config(['webhooks.allow_private_targets' => true]);

    $this->receiver = null;
    $this->port = freePort();
    $this->endpoint = WebhookEndpoint::factory()->create(['url' => "http://localhost:{$this->port}/webhooks"]);
    $this->order = Order::factory()->for($this->endpoint->shop)->create();
});

afterEach(function (): void {
    $this->receiver?->stop();
});

dataset('receivers', [
    'PHP' => fn () => [[PHP_BINARY, '-S', '127.0.0.1:{port}', 'receiver.php'], 'examples/webhooks/php'],
    'Node' => fn () => [['node', 'receiver.mjs'], 'examples/webhooks/node'],
]);

it('script mẫu nhận và kiểm đúng chữ ký, bỏ trùng theo event id, từ chối secret sai', function (array $command, string $directory): void {
    if ($command[0] === 'node' && ! nodeAvailable()) {
        $this->markTestSkipped('Máy này không có Node.');
    }

    $command = array_map(fn (string $part): string => str_replace('{port}', (string) $this->port, $part), $command);
    $this->receiver = startReceiver($command, base_path($directory), $this->port, (string) $this->endpoint->secret);

    app(WebhookDispatcher::class)->order(WebhookEventType::OrderPaid, $this->order);

    expect(app(DeliverDueWebhooks::class)())->toBe(1);

    $delivery = WebhookDelivery::sole();
    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and(WebhookAttempt::sole()->response_body)->toBe('ok');

    // Gửi lại cùng sự kiện: phía shop nhận ra trùng nhưng vẫn trả 2xx để gateway ngừng gửi.
    $sender = app(WebhookSender::class);
    expect($sender->send($delivery, DeliveryTrigger::Manual)->response_body)->toBe('duplicate');

    // Đổi secret ở gateway mà phía shop chưa cập nhật: chữ ký không khớp, phía shop trả 400.
    $this->endpoint->rotateSecret();
    $ping = app(WebhookDispatcher::class)->ping($this->endpoint->fresh());
    $attempt = $sender->send($ping, DeliveryTrigger::Manual);

    expect($attempt->response_code)->toBe(400)
        ->and($attempt->response_body)->toBe('invalid signature')
        ->and($ping->fresh()->status)->toBe(WebhookDeliveryStatus::Failed);
})->with('receivers');

it('ngắt phản hồi lớn hơn giới hạn thay vì đọc hết vào bộ nhớ', function (): void {
    $directory = storage_path('framework/testing/huge-response');
    @mkdir($directory, recursive: true);
    file_put_contents("{$directory}/router.php", '<?php echo str_repeat("x", 4 * 1024 * 1024);');
    config(['webhooks.max_response_bytes' => 64 * 1024]);

    $this->receiver = startReceiver([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", 'router.php'], $directory, $this->port, 'khong-dung');

    $ping = app(WebhookDispatcher::class)->ping($this->endpoint);
    $attempt = app(WebhookSender::class)->send($ping, DeliveryTrigger::Manual);

    expect($attempt->response_code)->toBeNull()
        ->and($attempt->error)->toBe('Phản hồi lớn hơn 64 KB, đã ngắt.');
});

it('hàm kiểm chữ ký mẫu của PHP và Node cho cùng kết quả', function (): void {
    require_once base_path('examples/webhooks/php/verify-signature.php');

    $secret = WebhookEndpoint::generateSecret();
    $payload = '{"id":"evt_01","type":"order.paid","data":{"order":{"description":"Bình gốm men lam"}}}';
    $now = 1_760_752_800;
    $valid = WebhookSignature::header($payload, $secret, $now);

    $cases = [
        'đúng' => [$valid, $payload, $now, true],
        'body bị sửa' => [$valid, str_replace('lam', 'lục', $payload), $now, false],
        'quá 5 phút' => [$valid, $payload, $now + 301, false],
        'nhiều v1, một cái đúng' => [str_replace('v1=', 'v1=deadbeef,v1=', $valid), $payload, $now, true],
        'thiếu t' => [substr($valid, strpos($valid, ',') + 1), $payload, $now, false],
        'header rỗng' => ['', $payload, $now, false],
    ];

    foreach ($cases as $name => [$header, $body, $at, $expected]) {
        expect(gateway_verify_signature($body, $header, $secret, 300, $at))->toBe($expected, "PHP: {$name}");
    }

    if (! nodeAvailable()) {
        return;
    }

    $module = 'file:///'.ltrim(str_replace('\\', '/', base_path('examples/webhooks/node/verify-signature.mjs')), '/');
    $script = <<<JS
        import { verifyGatewaySignature } from '{$module}';
        const cases = JSON.parse(process.env.CASES);
        const results = Object.fromEntries(Object.entries(cases).map(([name, [header, body, now]]) =>
          [name, verifyGatewaySignature(Buffer.from(body, 'utf8'), header, process.env.SECRET, { now })]));
        console.log(JSON.stringify(results));
        JS;

    $result = Process::env(['CASES' => json_encode($cases, JSON_UNESCAPED_UNICODE), 'SECRET' => $secret])
        ->run(['node', '--input-type=module', '-e', $script]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(json_decode($result->output(), true))->toBe(array_map(fn (array $case): bool => $case[3], $cases));
});
