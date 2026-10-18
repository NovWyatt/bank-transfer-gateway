<?php

namespace Tests;

use App\Models\ProviderConnection;
use App\Payments\Providers\SePaySignature;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /** Số id tăng dần cho payload giả lập, để mỗi lần gửi là một giao dịch mới. */
    private static int $simulatorId = 500_000;

    /**
     * Gửi body nguyên văn tới đường nhận của kết nối.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<JsonResponse>
     */
    public function postWebhook(ProviderConnection $connection, string $body, array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', "/hooks/{$connection->provider->value}/{$connection->uuid}", server: $server, content: $body);
    }

    /**
     * Payload định dạng SePay (dùng cho SePay và bộ giả lập), dựng từ fixture của tài liệu.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function sepayPayload(array $overrides = []): string
    {
        $data = json_decode(fixtureContent('providers/sepay/webhook.json'), true);
        $data['id'] = ++self::$simulatorId;
        $data['transactionDate'] = now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s');

        return (string) json_encode(array_merge($data, $overrides), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Gửi payload kiểu SePay đã ký HMAC bằng secret của kết nối.
     *
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<JsonResponse>
     */
    public function postSigned(ProviderConnection $connection, array $overrides = []): TestResponse
    {
        $body = $this->sepayPayload($overrides);
        $secret = (string) $connection->credential('secret');

        return $this->postWebhook($connection, $body, SePaySignature::headers($body, $secret, now()->getTimestamp()));
    }
}
