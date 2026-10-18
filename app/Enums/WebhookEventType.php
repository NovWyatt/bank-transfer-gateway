<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WebhookEventType: string implements HasLabel
{
    case OrderPaid = 'order.paid';
    case OrderPartiallyPaid = 'order.partially_paid';
    case OrderOverpaid = 'order.overpaid';
    case OrderExpired = 'order.expired';
    case OrderCancelled = 'order.cancelled';
    case TransactionUnmatched = 'transaction.unmatched';

    /** Chỉ gửi khi bấm "Gửi thử", không đăng ký được. */
    case Ping = 'webhook.ping';

    /** @return list<self> */
    public static function subscribable(): array
    {
        $types = [];

        foreach (self::cases() as $type) {
            if ($type !== self::Ping) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /** @return array<string, string> giá trị => nhãn, cho ô chọn trên dashboard */
    public static function options(): array
    {
        $options = [];

        foreach (self::subscribable() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }

    /** Sự kiện báo đơn vừa chuyển sang trạng thái này; null với trạng thái không báo. */
    public static function forOrderStatus(OrderStatus $status): ?self
    {
        return match ($status) {
            OrderStatus::Paid => self::OrderPaid,
            OrderStatus::PartiallyPaid => self::OrderPartiallyPaid,
            OrderStatus::Overpaid => self::OrderOverpaid,
            OrderStatus::Expired => self::OrderExpired,
            OrderStatus::Cancelled => self::OrderCancelled,
            OrderStatus::Pending => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OrderPaid => 'Đơn đã nhận đủ tiền',
            self::OrderPartiallyPaid => 'Đơn được trả một phần',
            self::OrderOverpaid => 'Đơn bị trả dư',
            self::OrderExpired => 'Đơn hết hạn',
            self::OrderCancelled => 'Đơn bị hủy',
            self::TransactionUnmatched => 'Tiền vào chưa khớp đơn',
            self::Ping => 'Gửi thử',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
