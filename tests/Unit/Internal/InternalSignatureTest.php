<?php

use App\Internal\InternalSignature;

/*
| Chữ ký của /internal/*. Worker (edge/) ký cùng chuỗi này: xem edge/test/signature.test.mjs.
*/

const NOW = 1_760_000_000;

it('chấp nhận chữ ký đúng và từ chối khi đổi bất kỳ phần nào', function (): void {
    $header = InternalSignature::header('bi-mat', 'POST', '/internal/tick', '{}', NOW);

    expect($header)->toMatch('/^t=1760000000,v1=[a-f0-9]{64}$/')
        ->and(InternalSignature::verify($header, 'bi-mat', 'POST', '/internal/tick', '{}', NOW))->toBeTrue()
        ->and(InternalSignature::verify($header, 'bi-mat', 'post', '/internal/tick', '{}', NOW))->toBeTrue()
        ->and(InternalSignature::verify($header, 'secret-khac', 'POST', '/internal/tick', '{}', NOW))->toBeFalse()
        ->and(InternalSignature::verify($header, 'bi-mat', 'POST', '/internal/deploy', '{}', NOW))->toBeFalse()
        ->and(InternalSignature::verify($header, 'bi-mat', 'GET', '/internal/tick', '{}', NOW))->toBeFalse()
        ->and(InternalSignature::verify($header, 'bi-mat', 'POST', '/internal/tick', '{"a":1}', NOW))->toBeFalse();
});

it('khớp vector mà Worker edge/ kiểm trong edge/test/index.test.mjs', function (): void {
    expect(InternalSignature::header('bi-mat-edge', 'POST', '/internal/tick', '', 1_760_000_000))
        ->toBe('t=1760000000,v1=067e7cebc52a503cf48e6987bd10182294831d264abe48a07419cc57066466f8');
});

it('từ chối chữ ký lệch giờ quá 5 phút', function (int $skew, bool $valid): void {
    $header = InternalSignature::header('bi-mat', 'POST', '/internal/tick', '', NOW);

    expect(InternalSignature::verify($header, 'bi-mat', 'POST', '/internal/tick', '', NOW + $skew))->toBe($valid);
})->with([
    'đúng giờ' => [0, true],
    'trễ 300 giây' => [300, true],
    'sớm 300 giây' => [-300, true],
    'trễ 301 giây' => [301, false],
    'sớm 301 giây' => [-301, false],
]);

it('từ chối header sai dạng', function (string $header): void {
    expect(InternalSignature::verify($header, 'bi-mat', 'POST', '/internal/tick', '', NOW))->toBeFalse();
})->with([
    'rỗng' => [''],
    'thiếu t' => ['v1='.str_repeat('a', 64)],
    'hex hoa' => ['t='.NOW.',v1='.str_repeat('A', 64)],
    'hex ngắn' => ['t='.NOW.',v1='.str_repeat('a', 63)],
    'thêm phần sau' => ['t='.NOW.',v1='.str_repeat('a', 64).',v0=x'],
    'xuống dòng cuối' => ['t='.NOW.',v1='.str_repeat('a', 64)."\n"],
]);
