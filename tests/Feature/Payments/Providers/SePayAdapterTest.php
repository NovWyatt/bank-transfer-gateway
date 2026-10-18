<?php

use App\Enums\Provider;
use App\Enums\TransferDirection;
use App\Models\ProviderConnection;
use App\Payments\Providers\Exceptions\InvalidPayload;
use App\Payments\Providers\Exceptions\InvalidSignature;
use App\Payments\Providers\SePayAdapter;
use App\Payments\Providers\SePaySignature;
use App\Payments\Providers\SimulatorAdapter;
use Illuminate\Http\Request;

const SEPAY_SECRET = 'whsec_thu_nghiem_khong_phai_that';

beforeEach(function () {
    $this->travelTo(now()->setTimestamp(1_790_000_000));
    $this->adapter = new SePayAdapter;
    $this->body = fixtureContent('providers/sepay/webhook.json');
    $this->hmacConnection = new ProviderConnection(['credentials' => ['secret' => SEPAY_SECRET]]);
});

/** Body đã sửa một vài trường, mã hóa lại thành JSON. */
function sepayBody(array $changes): string
{
    $data = array_merge(json_decode(fixtureContent('providers/sepay/webhook.json'), true), $changes);

    return json_encode($data, JSON_UNESCAPED_UNICODE);
}

function signedSePay(string $body, ?int $timestamp = null, string $secret = SEPAY_SECRET): Request
{
    return webhookRequest($body, SePaySignature::headers($body, $secret, $timestamp ?? now()->getTimestamp()));
}

describe('đọc payload', function () {
    it('đọc đúng ví dụ trong tài liệu SePay', function () {
        [$tx] = $this->adapter->parse(webhookRequest($this->body));

        expect($tx->provider)->toBe(Provider::SePay)
            ->and($tx->externalId)->toBe('92704')
            ->and($tx->accountNumber)->toBe('1017588888')
            ->and($tx->gateway)->toBe('Vietcombank')
            ->and($tx->direction)->toBe(TransferDirection::In)
            ->and($tx->amount)->toBe(5_000_000)
            ->and($tx->content)->toBe('SEVN63DC8E5C chuyen tien')
            ->and($tx->referenceCode)->toBe('FT24012345678')
            ->and($tx->providerCode)->toBe('SEVN63DC8E5C')
            ->and($tx->payerName)->toBeNull()
            ->and($tx->raw['accumulated'])->toBe(105_000_000);
    });

    it('đổi giờ Việt Nam sang UTC', function () {
        [$tx] = $this->adapter->parse(webhookRequest($this->body));

        expect($tx->transactedAt->getTimezone()->getName())->toBe('UTC')
            ->and($tx->transactedAt->format('Y-m-d H:i:s'))->toBe('2024-07-02 04:08:33');
    });

    it('nhận tiền ra và code null', function () {
        [$tx] = $this->adapter->parse(webhookRequest(sepayBody(['transferType' => 'out', 'code' => null])));

        expect($tx->direction)->toBe(TransferDirection::Out)
            ->and($tx->providerCode)->toBeNull();
    });

    it('nhận id = 0 của nút "Gửi thử"', function () {
        [$tx] = $this->adapter->parse(webhookRequest(sepayBody(['id' => 0])));

        expect($tx->externalId)->toBe('0');
    });

    it('đọc được body dạng form-urlencoded', function () {
        $body = http_build_query(json_decode($this->body, true));

        [$tx] = $this->adapter->parse(webhookRequest($body, contentType: 'application/x-www-form-urlencoded'));

        expect($tx->externalId)->toBe('92704')
            ->and($tx->amount)->toBe(5_000_000);
    });

    it('từ chối payload sai', function (string $body) {
        $this->adapter->parse(webhookRequest($body));
    })->with([
        'không phải JSON' => 'xin chao',
        'JSON không phải object' => '"chuoi"',
        'số tiền bằng 0' => fn () => sepayBody(['transferAmount' => 0]),
        'số tiền âm' => fn () => sepayBody(['transferAmount' => -5000]),
        'số tiền lẻ' => fn () => sepayBody(['transferAmount' => 5000.5]),
        'thiếu nội dung' => fn () => sepayBody(['content' => null]),
        'chiều lạ' => fn () => sepayBody(['transferType' => 'sideways']),
        'ngày không có thật' => fn () => sepayBody(['transactionDate' => '2026-02-30 10:00:00']),
        'ngày sai dạng' => fn () => sepayBody(['transactionDate' => '02/07/2024 11:08']),
        'id âm' => fn () => sepayBody(['id' => -1]),
    ])->throws(InvalidPayload::class);
});

describe('xác thực HMAC', function () {
    it('nhận chữ ký đúng', function () {
        $this->adapter->verify(signedSePay($this->body), $this->hmacConnection);
    })->throwsNoExceptions();

    it('từ chối khi sai secret', function () {
        $this->adapter->verify(signedSePay($this->body, secret: 'secret-khac'), $this->hmacConnection);
    })->throws(InvalidSignature::class, 'không khớp');

    it('từ chối khi body bị sửa sau khi ký', function () {
        $request = webhookRequest(
            str_replace('5000000', '9000000', $this->body),
            SePaySignature::headers($this->body, SEPAY_SECRET, now()->getTimestamp()),
        );

        $this->adapter->verify($request, $this->hmacConnection);
    })->throws(InvalidSignature::class, 'không khớp');

    it('nhận timestamp lệch đúng 5 phút', function (int $offset) {
        $this->adapter->verify(signedSePay($this->body, now()->getTimestamp() + $offset), $this->hmacConnection);
    })->with([-300, 300])->throwsNoExceptions();

    it('từ chối timestamp lệch quá 5 phút', function (int $offset) {
        $this->adapter->verify(signedSePay($this->body, now()->getTimestamp() + $offset), $this->hmacConnection);
    })->with([-301, 301])->throws(InvalidSignature::class, 'lệch');

    it('từ chối khi thiếu header', function () {
        $this->adapter->verify(webhookRequest($this->body), $this->hmacConnection);
    })->throws(InvalidSignature::class, 'Thiếu');
});

describe('xác thực API key', function () {
    beforeEach(function () {
        $this->apiKeyConnection = new ProviderConnection(['credentials' => ['api_key' => 'khoa-api-thu']]);
    });

    it('nhận API key đúng', function () {
        $request = webhookRequest($this->body, ['Authorization' => 'Apikey khoa-api-thu']);

        $this->adapter->verify($request, $this->apiKeyConnection);
    })->throwsNoExceptions();

    it('từ chối API key sai hoặc sai kiểu', function (string $authorization) {
        $this->adapter->verify(webhookRequest($this->body, ['Authorization' => $authorization]), $this->apiKeyConnection);
    })->with([
        'sai khóa' => 'Apikey khoa-khac',
        'kiểu Bearer' => 'Bearer khoa-api-thu',
        'rỗng' => '',
    ])->throws(InvalidSignature::class);
});

it('không nhận kết nối chưa có khóa (kiểu "None" của SePay)', function () {
    $this->adapter->verify(webhookRequest($this->body), new ProviderConnection(['credentials' => []]));
})->throws(InvalidSignature::class, 'chưa có khóa');

describe('bộ giả lập', function () {
    it('dùng đúng định dạng SePay nhưng gắn nhà cung cấp Simulator', function () {
        $adapter = new SimulatorAdapter;
        $adapter->verify(signedSePay($this->body), $this->hmacConnection);

        [$tx] = $adapter->parse(webhookRequest($this->body));

        expect($adapter->provider())->toBe(Provider::Simulator)
            ->and($tx->provider)->toBe(Provider::Simulator);
    });

    it('không nhận API key', function () {
        $connection = new ProviderConnection(['credentials' => ['api_key' => 'khoa-api-thu']]);

        (new SimulatorAdapter)->verify(webhookRequest($this->body, ['Authorization' => 'Apikey khoa-api-thu']), $connection);
    })->throws(InvalidSignature::class, 'chưa có secret');
});

it('trả phản hồi SePay coi là thành công', function () {
    $response = $this->adapter->acknowledge();

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getData(true))->toBe(['success' => true]);
});
