<?php

use App\Payments\VietQr\Crc16;
use App\Payments\VietQr\VietQrPayload;

/**
 * Tách chuỗi TLV một cấp thành [mã => giá trị].
 *
 * @return array<string, string>
 */
function parseTlv(string $data): array
{
    $fields = [];
    $offset = 0;

    while ($offset < strlen($data)) {
        $id = substr($data, $offset, 2);
        $length = (int) substr($data, $offset + 2, 2);
        $fields[$id] = substr($data, $offset + 4, $length);
        $offset += 4 + $length;
    }

    return $fields;
}

it('tính CRC-16/CCITT-FALSE đúng giá trị kiểm tra chuẩn', function () {
    // Giá trị kiểm tra công bố của CRC-16/CCITT-FALSE cho chuỗi "123456789".
    expect(Crc16::ccittFalse('123456789'))->toBe('29B1');
});

it('dựng QR động có số tiền và nội dung', function () {
    $qr = VietQrPayload::build('970416', '123456789', 150000, 'DH7K3QX');
    $fields = parseTlv($qr);

    expect($fields['00'])->toBe('01')
        ->and($fields['01'])->toBe('12')
        ->and($fields['53'])->toBe('704')
        ->and($fields['54'])->toBe('150000')
        ->and($fields['58'])->toBe('VN')
        ->and(parseTlv($fields['62']))->toBe(['08' => 'DH7K3QX']);

    $beneficiary = parseTlv($fields['38']);
    expect($beneficiary['00'])->toBe('A000000727')
        ->and(parseTlv($beneficiary['01']))->toBe(['00' => '970416', '01' => '123456789'])
        ->and($beneficiary['02'])->toBe('QRIBFTTA');
});

it('gắn CRC tính trên toàn chuỗi kể cả 6304', function () {
    $qr = VietQrPayload::build('970416', '123456789', 150000, 'DH7K3QX');

    expect(substr($qr, -8, 4))->toBe('6304')
        ->and(substr($qr, -4))->toBe(Crc16::ccittFalse(substr($qr, 0, -4)));
});

it('dựng QR tĩnh khi không có số tiền', function () {
    $fields = parseTlv(VietQrPayload::build('970416', '123456789'));

    expect($fields['01'])->toBe('11')
        ->and($fields)->not->toHaveKey('54')
        ->and($fields)->not->toHaveKey('62');
});

it('từ chối dữ liệu không hợp lệ', function (string $bin, string $account, ?int $amount, string $memo) {
    VietQrPayload::build($bin, $account, $amount, $memo);
})->throws(InvalidArgumentException::class)->with([
    'BIN 5 số' => ['97041', '123456789', 1000, 'DH7K3QX'],
    'số tài khoản có dấu cách' => ['970416', '1234 5678', 1000, 'DH7K3QX'],
    'số tiền âm' => ['970416', '123456789', -1, 'DH7K3QX'],
    'số tiền bằng 0' => ['970416', '123456789', 0, 'DH7K3QX'],
    'nội dung có dấu' => ['970416', '123456789', 1000, 'ĐƠN HÀNG'],
    'nội dung chữ thường' => ['970416', '123456789', 1000, 'dh7k3qx'],
    'nội dung quá 25 ký tự' => ['970416', '123456789', 1000, str_repeat('A', 26)],
]);
