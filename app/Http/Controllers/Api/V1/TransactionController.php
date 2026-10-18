<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionStatus;
use App\Enums\TransferDirection;
use App\Http\Resources\V1\TransactionResource;
use App\Models\Shop;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Giao dịch ngân hàng nhà cung cấp đã báo về cho shop sở hữu token.
 */
class TransactionController
{
    /**
     * Danh sách giao dịch, mới nhất trước, phân trang theo cursor.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'direction' => ['nullable', Rule::enum(TransferDirection::class)],
            /** Chỉ lấy giao dịch đã khớp với đơn này. */
            'order_code' => ['nullable', 'string', 'max:10'],
            /** Số giao dịch mỗi trang, mặc định 25. */
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var Shop $shop */
        $shop = $request->user('sanctum');

        $transactions = Transaction::query()
            ->where('shop_id', $shop->getKey())
            ->with(['order', 'bankAccount'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['direction'] ?? null, fn ($query, $direction) => $query->where('direction', $direction))
            ->when($filters['order_code'] ?? null, fn ($query, $code) => $query->whereRelation('order', 'code', strtoupper($code)))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return TransactionResource::collection($transactions);
    }
}
