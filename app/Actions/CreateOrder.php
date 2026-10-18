<?php

namespace App\Actions;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\Shop;
use App\Payments\OrderCode;
use Closure;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

final class CreateOrder
{
    public const DEFAULT_TTL_MINUTES = 30;

    /** Trùng 5 lần liền với 729 triệu tổ hợp gần như không xảy ra; nếu có thì là lỗi khác. */
    private const MAX_CODE_ATTEMPTS = 5;

    /** @var Closure(string): string */
    private readonly Closure $generateCode;

    /**
     * @param  (Closure(string): string)|null  $generateCode  thay bộ sinh mã, dùng trong test
     */
    public function __construct(?Closure $generateCode = null)
    {
        $this->generateCode = $generateCode ?? OrderCode::generate(...);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     *
     * @throws UniqueConstraintViolationException khi external_ref đã có trong shop
     */
    public function handle(
        Shop $shop,
        int $amount,
        OrderSource $source,
        ?string $externalRef = null,
        ?string $description = null,
        ?string $customerName = null,
        ?int $expiresInMinutes = null,
        ?BankAccount $bankAccount = null,
        ?array $metadata = null,
    ): Order {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Số tiền phải lớn hơn 0.');
        }

        $bankAccount ??= $shop->defaultBankAccount()->first()
            ?? throw new DomainException('Shop chưa có tài khoản ngân hàng mặc định.');

        if ($bankAccount->shop_id !== $shop->getKey()) {
            throw new DomainException('Tài khoản ngân hàng không thuộc shop này.');
        }

        $expiresAt = now()->addMinutes($expiresInMinutes ?? self::DEFAULT_TTL_MINUTES);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $shop->orders()->create([
                    'bank_account_id' => $bankAccount->getKey(),
                    'code' => ($this->generateCode)($shop->order_prefix),
                    'external_ref' => $externalRef,
                    'amount' => $amount,
                    'paid_amount' => 0,
                    'status' => OrderStatus::Pending,
                    'description' => $description,
                    'customer_name' => $customerName,
                    'expires_at' => $expiresAt,
                    'created_via' => $source,
                    'metadata' => $metadata,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Chỉ sinh lại khi trùng mã đơn. Trùng external_ref là lỗi của phía gọi.
                if ($e->index !== 'orders_code_unique' || $attempt >= self::MAX_CODE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }
}
