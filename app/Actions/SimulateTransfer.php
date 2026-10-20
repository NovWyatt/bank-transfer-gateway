<?php

namespace App\Actions;

use App\Enums\ConnectionStatus;
use App\Enums\Provider;
use App\Models\Order;
use App\Models\ProviderConnection;
use App\Payments\Ingest\IngestWebhook;
use App\Payments\Providers\SePaySignature;
use App\Tenancy\ShopScope;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Giả lập khách chuyển khoản vào tài khoản của đơn: payload kiểu SePay, ký bằng secret của kết nối
 * giả lập, rồi đưa vào IngestWebhook như webhook thật (kiểm chữ ký, lưu inbound_events, khớp đơn,
 * xếp webhook và tin Telegram).
 *
 * Chạy ngay trong tiến trình, không tự gọi HTTP vào chính app: trên Wasmer mỗi instance chỉ xử lý
 * một request một lúc, gọi vào chính mình là chờ nhau.
 */
final class SimulateTransfer
{
    public function __construct(
        private readonly IngestWebhook $ingest,
    ) {}

    /**
     * @return int mã HTTP mà đường nhận trả cho nhà cung cấp (200 là đã nhận)
     */
    public function handle(Order $order, ?int $amount = null, ?string $content = null): int
    {
        $order->loadMissing('bankAccount');
        $connection = $this->connection($order->shop_id);

        $body = (string) json_encode([
            'id' => random_int(100_000_000, 999_999_999),
            'gateway' => $order->bankAccount->bank_code,
            'transactionDate' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s'),
            'accountNumber' => $order->bankAccount->account_number,
            'subAccount' => '',
            'code' => null,
            'content' => $content ?? 'MBVCB.'.random_int(1_000_000, 9_999_999).'.'.$order->code.'.CT tu NGUYEN VAN B',
            'transferType' => 'in',
            'description' => '',
            'transferAmount' => $amount ?? $order->remainingAmount(),
            'accumulated' => 0,
            // Ghép hai nửa: PHP trên Wasmer là bản 32-bit, số nguyên vượt 2^31 - 1 thành float và random_int ném TypeError.
            'referenceCode' => 'FT'.random_int(100_000, 999_999).random_int(100_000, 999_999),
        ], JSON_UNESCAPED_UNICODE);

        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach (SePaySignature::headers($body, (string) $connection->credential('secret'), now()->getTimestamp()) as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create(
            route('hooks.receive', ['provider' => Provider::Simulator->value, 'uuid' => $connection->uuid]),
            'POST',
            server: $server,
            content: $body,
        );

        return $this->ingest->handle($request, $connection)->getStatusCode();
    }

    /** Kết nối giả lập của shop; chưa có thì tạo, secret ngẫu nhiên vì không ai ngoài app cần biết. */
    private function connection(int $shopId): ProviderConnection
    {
        $existing = ProviderConnection::withoutGlobalScope(ShopScope::class)
            ->where('shop_id', $shopId)
            ->where('provider', Provider::Simulator)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $connection = new ProviderConnection([
            'provider' => Provider::Simulator,
            'label' => 'Giả lập (tạo tự động)',
            'credentials' => ['secret' => Str::random(40)],
            'status' => ConnectionStatus::Active,
        ]);
        $connection->shop_id = $shopId;
        $connection->save();

        return $connection;
    }
}
