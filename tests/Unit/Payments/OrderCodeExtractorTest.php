<?php

use App\Payments\Memo\MemoNormalizer;
use App\Payments\Memo\OrderCodeExtractor;

it('chuẩn hóa nội dung chuyển khoản', function (?string $memo, string $expected) {
    expect(MemoNormalizer::normalize($memo))->toBe($expected);
})->with([
    ['Đặng Thị Ánh', 'DANG THI ANH'],
    ['MBVCB.123456.DH7K3QXM.CT tu', 'MBVCB 123456 DH7K3QXM CT TU'],
    ['  nhiều   khoảng   trắng  ', 'NHIEU KHOANG TRANG'],
    ['---', ''],
    [null, ''],
]);

it('tìm mã đơn trong nội dung', function (?string $memo, array $expected) {
    expect(OrderCodeExtractor::candidates($memo, ['DH']))->toBe($expected);
})->with([
    'chỉ có mã' => ['DH7K3QXM', ['DH7K3QXM']],
    'chữ thường' => ['dh7k3qxm', ['DH7K3QXM']],
    'có chữ phía trước' => ['Thanh toan DH7K3QXM', ['DH7K3QXM']],
    'có chữ phía sau' => ['DH7K3QXM chuyen tien', ['DH7K3QXM']],
    'tiền tố ngân hàng có dấu chấm' => ['MBVCB.123456.DH7K3QXM.CT tu 0123 NGUYEN VAN A', ['DH7K3QXM']],
    'dính liền tiền tố và hậu tố' => ['MBVCB123456DH7K3QXMCT', ['DH7K3QXM']],
    'gạch nối giữa mã' => ['DH-7K3QXM', ['DH7K3QXM']],
    'dấu cách giữa mã' => ['DH 7K3QXM', ['DH7K3QXM']],
    'có dấu tiếng Việt' => ['Đơn hàng DH7K3QXM', ['DH7K3QXM']],
    'dấu chấm cuối' => ['DH7K3QXM.', ['DH7K3QXM']],
    'trong ngoặc' => ['(DH7K3QXM)', ['DH7K3QXM']],
    'tiền tố IBFT' => ['IBFT DH7K3QXM', ['DH7K3QXM']],
    'lặp hai lần' => ['DH7K3QXM DH7K3QXM', ['DH7K3QXM']],
    'hai mã khác nhau' => ['DH7K3QXM va DH9ABCDE', ['DH7K3QXM', 'DH9ABCDE']],
    'không có mã' => ['chuyen tien ao', []],
    'chuỗi rỗng' => ['', []],
    'null' => [null, []],
    'thiếu ký tự' => ['DH7K3QX', []],
    'gõ chữ O thay số' => ['DH7K3QXO', []],
    'dính số phía sau' => ['DH7K3QXM1', ['DH7K3QXM']],
    'có nhãn ND:' => ['ND:DH7K3QXM', ['DH7K3QXM']],
    'khoảng trắng hai đầu' => ['  dh7k3qxm  ', ['DH7K3QXM']],
    'nhãn mã giao dịch' => ['Ma GD ACSP/ DH7K3QXM', ['DH7K3QXM']],
    'kiểu nội dung của VCB' => ['TKThe :0123456789, tai VCB. DH7K3QXM -CTLNHIDO000001234567', ['DH7K3QXM']],
    'chỉ có tiền tố' => ['DHX', []],
]);

it('ưu tiên mã nhà cung cấp đã tách sẵn', function () {
    expect(OrderCodeExtractor::candidates('DH9ABCDE', ['DH'], ' dh7k3qxm '))->toBe(['DH7K3QXM', 'DH9ABCDE']);
});

it('bỏ qua mã nhà cung cấp không đúng dạng', function () {
    expect(OrderCodeExtractor::candidates('DH7K3QXM', ['DH'], 'XYZ'))->toBe(['DH7K3QXM']);
});

it('tìm theo nhiều tiền tố', function () {
    expect(OrderCodeExtractor::candidates('SHOPABCDEF2', ['DH', 'SHOP']))->toBe(['SHOPABCDEF']);
});

it('vẫn trả về ứng viên trùng tình cờ, để bộ khớp lọc bằng DB', function () {
    // "DHAKA BANK" dính liền thành "DHAKABANK", có đoạn trông như mã. Không có đơn nào như vậy thì không khớp.
    expect(OrderCodeExtractor::candidates('DHAKA BANK', ['DH']))->toBe(['DHAKABAN']);
});
