<?php

namespace App\Payments\Providers;

use App\Enums\Provider;
use App\Models\ProviderConnection;
use App\Payments\Providers\Exceptions\InvalidPayload;
use App\Payments\Providers\Exceptions\InvalidSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

interface ProviderAdapter
{
    public function provider(): Provider;

    /**
     * Xác thực request đến từ đúng nhà cung cấp, với khóa của kết nối này.
     * Luôn đọc body gốc ($request->getContent()), không đọc dữ liệu đã giải mã.
     *
     * @throws InvalidSignature
     */
    public function verify(Request $request, ProviderConnection $connection): void;

    /**
     * @return list<NormalizedTransaction>
     *
     * @throws InvalidPayload
     */
    public function parse(Request $request): array;

    /** Phản hồi nhà cung cấp coi là đã nhận thành công. */
    public function acknowledge(): JsonResponse;
}
