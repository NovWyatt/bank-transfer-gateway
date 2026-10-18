<?php

namespace App\Payments\Banks;

use RuntimeException;

/**
 * Danh sách ngân hàng đọc từ database/data/banks.json (chụp từ api.vietqr.io).
 * Không gọi API lúc chạy: danh sách hiếm khi đổi, cập nhật bằng tay khi cần.
 */
final class BankDirectory
{
    /** @var array<string, Bank>|null khóa là BIN */
    private ?array $banks = null;

    public function __construct(
        private readonly string $path,
    ) {}

    /** @return array<string, Bank> */
    public function all(): array
    {
        return $this->banks ??= $this->load();
    }

    public function find(string $bin): ?Bank
    {
        return $this->all()[$bin] ?? null;
    }

    /** Tìm theo mã viết tắt, không phân biệt hoa thường ("vcb", "VCB"). */
    public function findByCode(string $code): ?Bank
    {
        $code = strtoupper(trim($code));

        foreach ($this->all() as $bank) {
            if (strtoupper($bank->code) === $code) {
                return $bank;
            }
        }

        return null;
    }

    /** @return array<string, Bank> */
    private function load(): array
    {
        $json = @file_get_contents($this->path);

        if ($json === false) {
            throw new RuntimeException("Không đọc được danh sách ngân hàng ở {$this->path}.");
        }

        /** @var array{banks: list<array{bin: string, code: string, short_name: string, name: string, transfer_supported: bool}>} $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        $banks = [];

        foreach ($data['banks'] as $row) {
            $banks[$row['bin']] = new Bank(
                bin: $row['bin'],
                code: $row['code'],
                shortName: $row['short_name'],
                name: $row['name'],
                transferSupported: $row['transfer_supported'],
            );
        }

        return $banks;
    }
}
