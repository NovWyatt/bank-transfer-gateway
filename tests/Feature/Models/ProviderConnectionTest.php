<?php

use App\Enums\ShopRole;
use App\Models\ProviderConnection;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('sinh uuid v4 cho URL webhook, khóa chính vẫn tự tăng', function () {
    $connection = ProviderConnection::factory()->create();

    expect($connection->id)->toBeInt()
        ->and($connection->uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('mã hóa thông tin xác thực khi lưu và ẩn khi xuất mảng', function () {
    $connection = ProviderConnection::factory()->create([
        'credentials' => ['secret' => 'bi-mat-rat-dai-123'],
    ]);

    $stored = DB::table('provider_connections')->where('id', $connection->id)->value('credentials');

    expect($stored)->not->toContain('bi-mat-rat-dai-123')
        ->and($connection->fresh()->credential('secret'))->toBe('bi-mat-rat-dai-123')
        ->and($connection->credential('khong-co'))->toBeNull()
        ->and($connection->toArray())->not->toHaveKey('credentials');
});

it('đọc vai trò của user trong shop', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $stranger = User::factory()->create();

    $shop = Shop::factory()
        ->withMember($owner)
        ->withMember($staff, ShopRole::Staff)
        ->create();

    expect($owner->roleIn($shop))->toBe(ShopRole::Owner)
        ->and($staff->roleIn($shop))->toBe(ShopRole::Staff)
        ->and($stranger->roleIn($shop))->toBeNull();
});
