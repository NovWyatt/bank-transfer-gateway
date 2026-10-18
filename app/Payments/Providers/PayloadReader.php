<?php

namespace App\Payments\Providers;

use App\Payments\Providers\Exceptions\InvalidPayload;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Đọc từng trường của payload theo kiểu, báo InvalidPayload kèm tên trường khi sai.
 */
final class PayloadReader
{
    public const VIETNAM_TZ = 'Asia/Ho_Chi_Minh';

    /**
     * @param  array<mixed>  $data
     */
    public function __construct(
        private readonly array $data,
        private readonly string $context,
    ) {}

    /**
     * Giải mã body JSON thành mảng. Body form-urlencoded (SePay cho chọn) cũng đọc được.
     *
     * @return array<mixed>
     */
    public static function decodeBody(string $body, ?string $contentType): array
    {
        if ($contentType !== null && str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $form);

            return $form;
        }

        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidPayload('Body không phải JSON hợp lệ: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($data)) {
            throw new InvalidPayload('Body JSON phải là object.');
        }

        return $data;
    }

    /** @return array<mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            throw $this->invalid($key, 'phải là chuỗi');
        }

        return $value;
    }

    /** Null, thiếu, chuỗi rỗng hoặc "N/A" (Pay2S dùng khi ngân hàng không gửi) đều thành null. */
    public function optionalString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || $value === 'N/A' ? null : $value;
    }

    /** Số nguyên không âm; nhận cả chuỗi chữ số vì body dạng form chỉ có chuỗi. */
    public function unsignedInt(string $key): int
    {
        $value = $this->data[$key] ?? null;

        if (is_string($value) && $value !== '' && ctype_digit($value) && strlen($value) <= 18) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 0) {
            throw $this->invalid($key, 'phải là số nguyên không âm');
        }

        return $value;
    }

    public function positiveInt(string $key): int
    {
        $value = $this->unsignedInt($key);

        if ($value === 0) {
            throw $this->invalid($key, 'phải lớn hơn 0');
        }

        return $value;
    }

    /** Giờ Việt Nam dạng "YYYY-MM-DD HH:mm:ss", trả về UTC. */
    public function vietnamTime(string $key): CarbonImmutable
    {
        $value = $this->string($key);

        try {
            // Carbon 3 ném lỗi khi sai dạng, khác DateTime gốc trả false.
            $time = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $value, self::VIETNAM_TZ);
        } catch (InvalidFormatException) {
            $time = null;
        }

        // createFromFormat chấp nhận ngày tràn như 2026-02-30; so lại để loại.
        if (! $time instanceof CarbonImmutable || $time->format('Y-m-d H:i:s') !== $value) {
            throw $this->invalid($key, 'phải có dạng YYYY-MM-DD HH:mm:ss');
        }

        return $time->utc();
    }

    public function nested(string $key): self
    {
        $value = $this->data[$key] ?? [];

        return new self(is_array($value) ? $value : [], "{$this->context}.{$key}");
    }

    private function invalid(string $key, string $reason): InvalidPayload
    {
        return new InvalidPayload("Trường {$this->context}.{$key} {$reason}.");
    }
}
