<?php

use App\Payments\Banks\BankDirectory;

beforeEach(function () {
    $this->path = dirname(__DIR__, 3).'/database/data/banks.json';
    $this->banks = new BankDirectory($this->path);
});

it('đọc đủ danh sách, mỗi BIN là 6 chữ số và không trùng', function () {
    $raw = json_decode((string) file_get_contents($this->path), true);
    $all = $this->banks->all();

    expect($all)->toHaveCount(count($raw['banks']))
        ->and(count($all))->toBeGreaterThanOrEqual(70);

    foreach ($all as $bin => $bank) {
        expect((string) $bin)->toMatch('/^\d{6}$/')
            ->and($bank->bin)->toBe((string) $bin);
    }
});

it('tìm theo BIN', function () {
    $bank = $this->banks->find('970436');

    expect($bank)->not->toBeNull()
        ->and($bank->code)->toBe('VCB')
        ->and($bank->shortName)->toBe('Vietcombank');
});

it('tìm theo mã viết tắt, không phân biệt hoa thường', function () {
    expect($this->banks->findByCode(' vcb ')?->bin)->toBe('970436')
        ->and($this->banks->findByCode('MB')?->bin)->toBe('970422');
});

it('trả null khi không có', function () {
    expect($this->banks->find('000000'))->toBeNull()
        ->and($this->banks->findByCode('KHONGCO'))->toBeNull();
});

it('báo lỗi rõ khi không đọc được file', function () {
    (new BankDirectory('/khong/co/file.json'))->all();
})->throws(RuntimeException::class, 'Không đọc được danh sách ngân hàng');
