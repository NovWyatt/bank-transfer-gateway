<?php

use App\Payments\OrderCode;

it('sinh mã gồm tiền tố và 6 ký tự trong bảng chữ cái', function () {
    foreach (range(1, 200) as $_) {
        $code = OrderCode::generate('DH');

        expect($code)->toHaveLength(8)
            ->and(OrderCode::isWellFormed($code, 'DH'))->toBeTrue();
    }
});

it('không bao giờ dùng ký tự dễ đọc nhầm', function () {
    $codes = implode('', array_map(fn () => substr(OrderCode::generate('DH'), 2), range(1, 500)));

    expect($codes)->not->toMatch('/[01ILOU]/');
});

it('bảng chữ cái có đúng 30 ký tự không trùng', function () {
    expect(strlen(OrderCode::ALPHABET))->toBe(30)
        ->and(count(array_unique(str_split(OrderCode::ALPHABET))))->toBe(30);
});

it('từ chối tiền tố không hợp lệ', function (string $prefix) {
    OrderCode::generate($prefix);
})->throws(InvalidArgumentException::class)->with(['D', 'DHABC', 'dh', 'D1', 'Đ']);

it('nhận tiền tố 2 đến 4 chữ cái', function (string $prefix) {
    expect(OrderCode::generate($prefix))->toStartWith($prefix);
})->with(['DH', 'ABC', 'SHOP']);

it('kiểm tra mã đúng dạng', function (string $code, bool $expected) {
    expect(OrderCode::isWellFormed($code, 'DH'))->toBe($expected);
})->with([
    ['DH7K3QXM', true],
    ['DH7K3QX', false],
    ['DH7K3QXMX', false],
    ['DH7K3QXO', false],
    ['dh7k3qxm', false],
    ['XX7K3QXM', false],
]);
