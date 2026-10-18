<?php

namespace App\Tenancy;

use App\Models\Shop;

/**
 * Shop đang được thao tác trong request hoặc job hiện tại.
 *
 * Đăng ký dạng scoped nên tự xóa sau mỗi request và mỗi job.
 * Khi chưa đặt shop, global scope không lọc gì: đường nhận webhook,
 * lệnh console và job chạy trên mọi shop. Dashboard luôn đặt shop
 * qua tenancy của Filament, và bộ test cách ly tenant kiểm tra điều đó.
 */
final class CurrentShop
{
    private ?Shop $shop = null;

    public function set(?Shop $shop): void
    {
        $this->shop = $shop;
    }

    public function get(): ?Shop
    {
        return $this->shop;
    }

    public function id(): ?int
    {
        return $this->shop?->getKey();
    }

    public function has(): bool
    {
        return $this->shop !== null;
    }

    /**
     * Chạy $callback với shop khác, rồi trả lại shop cũ kể cả khi có lỗi.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(?Shop $shop, callable $callback): mixed
    {
        $previous = $this->shop;
        $this->shop = $shop;

        try {
            return $callback();
        } finally {
            $this->shop = $previous;
        }
    }
}
