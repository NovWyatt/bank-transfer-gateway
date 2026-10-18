<?php

use App\Enums\Provider;
use App\Enums\TransferDirection;
use App\Models\ProviderConnection;
use App\Payments\Providers\AdapterRegistry;
use App\Payments\Providers\Exceptions\InvalidPayload;
use App\Payments\Providers\Exceptions\InvalidSignature;
use App\Payments\Providers\Pay2SAdapter;

beforeEach(function () {
    $this->adapter = new Pay2SAdapter;
    $this->connection = new ProviderConnection(['credentials' => ['token' => 'p2s_token_thu_nghiem']]);
});

describe('đọc payload', function () {
    it('đọc đúng ví dụ trong tài liệu Pay2S', function () {
        $transactions = $this->adapter->parse(webhookRequest(fixtureContent('providers/pay2s/webhook.json')));

        expect($transactions)->toHaveCount(1);

        [$tx] = $transactions;

        expect($tx->provider)->toBe(Provider::Pay2S)
            ->and($tx->externalId)->toBe('10001')
            ->and($tx->accountNumber)->toBe('P2S99999999')
            ->and($tx->gateway)->toBe('ACB')
            ->and($tx->direction)->toBe(TransferDirection::In)
            ->and($tx->amount)->toBe(2000)
            ->and($tx->content)->toBe('THANHTOAN DH10001')
            ->and($tx->referenceCode)->toBe('DEMO10001')
            ->and($tx->providerCode)->toBe('DH10001')
            ->and($tx->transactedAt->format('Y-m-d H:i:s'))->toBe('2026-10-11 02:00:00')
            ->and($tx->payerName)->toBe('NGUYEN VAN A')
            ->and($tx->payerAccount)->toBe('0123456789');
    });

    it('coi "N/A" là không có thông tin người chuyển', function () {
        [$tx] = $this->adapter->parse(webhookRequest(fixtureContent('providers/pay2s/webhook-extra-na.json')));

        expect($tx->payerName)->toBeNull()
            ->and($tx->payerAccount)->toBeNull()
            ->and($tx->providerCode)->toBeNull();
    });

    it('giữ nguyên số tài khoản ảo', function () {
        [$tx] = $this->adapter->parse(webhookRequest(fixtureContent('providers/pay2s/webhook-va.json')));

        expect($tx->accountNumber)->toBe('963869123456')
            ->and($tx->payerName)->toBeNull();
    });

    it('duyệt hết mảng khi có nhiều giao dịch, kể cả tiền ra', function () {
        $data = json_decode(fixtureContent('providers/pay2s/webhook.json'), true);
        $second = array_merge($data['transactions'][0], ['id' => 10002, 'transferType' => 'OUT', 'transferAmount' => 15000]);
        $data['transactions'][] = $second;

        $transactions = $this->adapter->parse(webhookRequest(json_encode($data)));

        expect($transactions)->toHaveCount(2)
            ->and($transactions[1]->externalId)->toBe('10002')
            ->and($transactions[1]->direction)->toBe(TransferDirection::Out)
            ->and($transactions[1]->amount)->toBe(15000);
    });

    it('từ chối payload sai', function (string $body) {
        $this->adapter->parse(webhookRequest($body));
    })->with([
        'thiếu transactions' => '{"id": 10001}',
        'mảng rỗng' => '{"transactions": []}',
        'không phải mảng tuần tự' => '{"transactions": {"a": {}}}',
        'phần tử không phải object' => '{"transactions": [1]}',
        'thiếu số tiền' => fn () => str_replace('"transferAmount": 2000,', '', fixtureContent('providers/pay2s/webhook.json')),
    ])->throws(InvalidPayload::class);
});

describe('xác thực Bearer token', function () {
    it('nhận token đúng, không phân biệt hoa thường ở chữ Bearer', function (string $scheme) {
        $request = webhookRequest(fixtureContent('providers/pay2s/webhook.json'), ['Authorization' => "{$scheme} p2s_token_thu_nghiem"]);

        $this->adapter->verify($request, $this->connection);
    })->with(['Bearer', 'bearer'])->throwsNoExceptions();

    it('từ chối token sai hoặc thiếu', function (?string $authorization) {
        $headers = $authorization === null ? [] : ['Authorization' => $authorization];

        $this->adapter->verify(webhookRequest(fixtureContent('providers/pay2s/webhook.json'), $headers), $this->connection);
    })->with([
        'sai token' => 'Bearer token-khac',
        'thiếu chữ Bearer' => 'p2s_token_thu_nghiem',
        'thiếu header' => null,
    ])->throws(InvalidSignature::class);

    it('từ chối khi kết nối chưa có token', function () {
        $request = webhookRequest(fixtureContent('providers/pay2s/webhook.json'), ['Authorization' => 'Bearer bat-ky']);

        $this->adapter->verify($request, new ProviderConnection(['credentials' => []]));
    })->throws(InvalidSignature::class, 'chưa có token');
});

it('chọn đúng adapter theo nhà cung cấp', function (Provider $provider) {
    expect((new AdapterRegistry)->for($provider)->provider())->toBe($provider);
})->with([Provider::SePay, Provider::Pay2S, Provider::Simulator]);

it('báo rõ payOS chưa hỗ trợ', function () {
    (new AdapterRegistry)->for(Provider::PayOS);
})->throws(InvalidArgumentException::class, 'payOS');
