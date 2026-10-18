<?php

namespace App\Webhooks;

use App\Enums\DeliveryTrigger;
use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookAttempt;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Security\UnsafeUrl;
use App\Webhooks\Security\UrlGuard;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Gửi một bản ghi webhook một lần, ghi nhật ký, rồi cập nhật lịch gửi lại và sức khỏe endpoint.
 */
final class WebhookSender
{
    public function __construct(
        private readonly UrlGuard $guard,
    ) {}

    public function send(WebhookDelivery $delivery, DeliveryTrigger $trigger): WebhookAttempt
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $delivery->endpoint()->withoutGlobalScopes()->firstOrFail();

        // Endpoint đã tắt thì nhịp tự động bỏ qua; owner bấm gửi tay vẫn gửi để thử trước khi bật lại.
        if ($trigger === DeliveryTrigger::Automatic && ! $endpoint->active) {
            return $this->skip($delivery);
        }

        $started = hrtime(true);
        [$code, $body, $error] = $this->post($delivery, $endpoint);
        $durationMs = intdiv(hrtime(true) - $started, 1_000_000);

        return DB::transaction(function () use ($delivery, $endpoint, $trigger, $code, $body, $error, $durationMs): WebhookAttempt {
            $attempt = $delivery->attempts()->create([
                'trigger' => $trigger,
                'response_code' => $code,
                'response_body' => $body,
                'error' => $error,
                'duration_ms' => $durationMs,
            ]);

            $this->updateDelivery($delivery, $trigger, $attempt->succeeded(), $code);
            $this->updateEndpoint($endpoint, $attempt->succeeded());

            return $attempt;
        });
    }

    /**
     * @return array{?int, ?string, ?string} mã HTTP, 2 KB đầu của phản hồi, lỗi
     */
    private function post(WebhookDelivery $delivery, WebhookEndpoint $endpoint): array
    {
        $maxBytes = (int) config('webhooks.max_response_bytes');
        $tooLarge = false;

        try {
            $target = $this->guard->check($endpoint->url);

            $response = Http::withHeaders([
                'User-Agent' => 'BankTransferGateway-Webhooks/1.0',
                'X-Gateway-Event-Id' => $delivery->event_id,
                'X-Gateway-Event-Type' => $delivery->event_type->value,
                WebhookSignature::HEADER => WebhookSignature::header($delivery->payload, (string) $endpoint->secret, now()->getTimestamp()),
            ])
                ->withBody($delivery->payload, 'application/json')
                ->timeout((int) config('webhooks.timeout'))
                ->connectTimeout((int) config('webhooks.connect_timeout'))
                ->withoutRedirecting()
                ->withOptions([
                    'protocols' => [$target->scheme],
                    'curl' => $target->curlOptions(),
                    // Guzzle 8 chỉ nhận một số tùy chọn curl (không có CURLOPT_MAXFILESIZE).
                    // Hàm progress trả true thì curl ngừng tải: chặn phản hồi khổng lồ làm tràn bộ nhớ.
                    'progress' => function (int $expected, int $downloaded) use ($maxBytes, &$tooLarge): bool {
                        return $tooLarge = $downloaded > $maxBytes;
                    },
                ])
                ->post($target->url);

            return [$response->status(), self::snippet($response->body()), null];
        } catch (UnsafeUrl $e) {
            return [null, null, 'URL bị chặn: '.$e->getMessage()];
        } catch (ConnectionException|TransferException $e) {
            return [null, null, $tooLarge ? 'Phản hồi lớn hơn '.intdiv($maxBytes, 1024).' KB, đã ngắt.' : $e->getMessage()];
        }
    }

    private function updateDelivery(WebhookDelivery $delivery, DeliveryTrigger $trigger, bool $succeeded, ?int $code): void
    {
        $delivery->last_attempt_at = now()->toImmutable();
        $delivery->last_response_code = $code;

        if ($trigger === DeliveryTrigger::Automatic) {
            $delivery->attempt_count++;
        }

        if ($succeeded) {
            $delivery->status = WebhookDeliveryStatus::Succeeded;
            $delivery->next_attempt_at = null;
            $delivery->delivered_at = now()->toImmutable();
        } elseif ($trigger === DeliveryTrigger::Automatic) {
            $delays = (array) config('webhooks.retry_minutes');
            $delay = $delays[$delivery->attempt_count - 1] ?? null;

            $delivery->next_attempt_at = $delay === null ? null : now()->addMinutes((int) $delay)->toImmutable();
            $delivery->status = $delay === null ? WebhookDeliveryStatus::Failed : WebhookDeliveryStatus::Pending;
        } elseif ($delivery->status === WebhookDeliveryStatus::Pending && $delivery->next_attempt_at === null) {
            // Gửi tay hỏng: giữ nguyên lịch gửi lại nếu có. Bản ghi không có lịch (gửi thử) thì kết thúc ở đây.
            $delivery->status = WebhookDeliveryStatus::Failed;
        }

        $delivery->save();
    }

    private function updateEndpoint(WebhookEndpoint $endpoint, bool $succeeded): void
    {
        // Khóa dòng endpoint: nhịp tự động và nút gửi tay có thể cùng cập nhật bộ đếm.
        $locked = WebhookEndpoint::query()->withoutGlobalScopes()->whereKey($endpoint->getKey())->lockForUpdate()->firstOrFail();

        if ($succeeded) {
            $locked->forceFill([
                'consecutive_failures' => 0,
                'failing_since' => null,
                'last_success_at' => now(),
            ])->save();

            return;
        }

        $locked->consecutive_failures++;
        $locked->failing_since ??= now()->toImmutable();

        $failingLongEnough = $locked->failing_since->lessThanOrEqualTo(now()->subHours((int) config('webhooks.disable_after_hours')));

        if ($locked->active && $failingLongEnough && $locked->consecutive_failures >= (int) config('webhooks.disable_after_failures')) {
            $locked->active = false;
            $locked->disabled_at = now()->toImmutable();
            $locked->disabled_reason = "Tự tắt sau {$locked->consecutive_failures} lần gửi hỏng liên tiếp từ "
                .$locked->failing_since->format('H:i d/m/Y').' (UTC).';
        }

        $locked->save();
    }

    private function skip(WebhookDelivery $delivery): WebhookAttempt
    {
        return DB::transaction(function () use ($delivery): WebhookAttempt {
            $delivery->forceFill([
                'status' => WebhookDeliveryStatus::Skipped,
                'next_attempt_at' => null,
            ])->save();

            return $delivery->attempts()->create([
                'trigger' => DeliveryTrigger::Automatic,
                'error' => 'Endpoint đang tắt, không gửi.',
                'duration_ms' => 0,
            ]);
        });
    }

    /**
     * 2 KB đầu của phản hồi, cắt đúng ranh giới ký tự UTF-8 và thay byte lỗi:
     * cột utf8mb4 ở chế độ strict từ chối chuỗi UTF-8 hỏng.
     */
    private static function snippet(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        return mb_scrub(mb_strcut($body, 0, (int) config('webhooks.response_snippet_bytes'), 'UTF-8'), 'UTF-8');
    }
}
