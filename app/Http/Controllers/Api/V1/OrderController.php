<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CancelOrder;
use App\Actions\CreateOrder;
use App\Api\ApiError;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Http\Requests\V1\StoreOrderRequest;
use App\Http\Resources\V1\OrderResource;
use App\Models\Order;
use App\Models\Shop;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Đơn hàng của shop sở hữu token.
 */
class OrderController
{
    /**
     * Danh sách đơn, mới nhất trước, phân trang theo cursor.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'external_ref' => ['nullable', 'string', 'max:100'],
            /** Số đơn mỗi trang, mặc định 25. */
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = $this->orders($request)
            ->with(['bankAccount', 'shop'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['external_ref'] ?? null, fn ($query, $ref) => $query->where('external_ref', $ref))
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    /**
     * Tạo đơn. Gửi kèm header Idempotency-Key để gửi lại an toàn khi mạng lỗi.
     *
     * @response 201 OrderResource
     */
    public function store(StoreOrderRequest $request, CreateOrder $create): JsonResponse
    {
        $data = $request->validated();

        try {
            $order = $create->handle(
                shop: $this->shop($request),
                amount: (int) $data['amount'],
                source: OrderSource::Api,
                externalRef: $data['external_ref'] ?? null,
                description: $data['description'] ?? null,
                customerName: $data['customer_name'] ?? null,
                expiresInMinutes: isset($data['expires_in']) ? (int) $data['expires_in'] : null,
            );
        } catch (DomainException $e) {
            return ApiError::json(422, 'no_bank_account', $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            // Hai request cùng external_ref chạy song song: request sau thua ở UNIQUE.
            return ApiError::json(409, 'external_ref_taken', 'external_ref đã có đơn khác dùng.');
        }

        return (new OrderResource($order->load(['bankAccount', 'shop'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Một đơn theo mã.
     */
    public function show(Request $request, string $code): OrderResource|JsonResponse
    {
        $order = $this->find($request, $code);

        return $order === null ? self::notFound() : new OrderResource($order);
    }

    /**
     * Hủy đơn còn nhận tiền. Tiền chuyển vào sau đó để "chưa khớp".
     */
    public function cancel(Request $request, string $code, CancelOrder $cancel): OrderResource|JsonResponse
    {
        $order = $this->find($request, $code);

        if ($order === null) {
            return self::notFound();
        }

        try {
            $cancelled = $cancel->handle($order, null, $request->ip());
        } catch (DomainException $e) {
            return ApiError::json(409, 'order_not_cancellable', $e->getMessage());
        }

        return new OrderResource($cancelled->load(['bankAccount', 'shop']));
    }

    /** @return Builder<Order> */
    private function orders(Request $request): Builder
    {
        // Lọc shop tường minh, không chỉ dựa vào global scope.
        return Order::query()->where('shop_id', $this->shop($request)->getKey());
    }

    private function find(Request $request, string $code): ?Order
    {
        return $this->orders($request)
            ->with(['bankAccount', 'shop'])
            ->where('code', strtoupper($code))
            ->first();
    }

    private function shop(Request $request): Shop
    {
        /** @var Shop */
        return $request->user('sanctum');
    }

    private static function notFound(): JsonResponse
    {
        return ApiError::json(404, 'order_not_found', 'Không có đơn này trong shop.');
    }
}
