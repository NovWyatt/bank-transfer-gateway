<?php

namespace App\Payments\Providers;

use App\Enums\Provider;
use App\Models\ProviderConnection;
use App\Payments\Providers\Exceptions\InvalidSignature;
use Illuminate\Http\Request;

/**
 * Bộ giả lập cho demo và test: payload và chữ ký HMAC giống hệt SePay,
 * đi đúng đường nhận thật. Chỉ nhận HMAC, không nhận API key.
 */
final class SimulatorAdapter extends SePayAdapter
{
    public function provider(): Provider
    {
        return Provider::Simulator;
    }

    public function verify(Request $request, ProviderConnection $connection): void
    {
        $secret = $connection->credential('secret')
            ?? throw new InvalidSignature('Kết nối giả lập chưa có secret.');

        SePaySignature::verify(
            $request->getContent(),
            $secret,
            $request->header(SePaySignature::SIGNATURE_HEADER),
            $request->header(SePaySignature::TIMESTAMP_HEADER),
            now()->getTimestamp(),
        );
    }
}
