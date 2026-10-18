<?php

use App\Enums\ShopRole;
use App\Filament\Pages\TelegramNotifications;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\User;
use App\Telegram\TelegramLinkCodes;
use App\Tenancy\CurrentShop;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'services.telegram.bot_token' => '123:token-test',
        'services.telegram.bot_username' => 'btg_test_bot',
    ]);

    $this->owner = User::factory()->create();
    $this->staff = User::factory()->create();
    $this->shop = Shop::factory()
        ->withMember($this->owner)
        ->withMember($this->staff, ShopRole::Staff)
        ->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel('app');
    Filament::setTenant($this->shop);
    app(CurrentShop::class)->set($this->shop);
});

it('chỉ owner vào được trang Telegram', function (): void {
    $this->actingAs($this->owner)->get("/app/{$this->shop->slug}/telegram")->assertOk();
    $this->actingAs($this->staff)->get("/app/{$this->shop->slug}/telegram")->assertForbidden();
});

it('nút nối tạo link t.me với mã dùng được đúng cho shop này', function (): void {
    $component = Livewire::test(TelegramNotifications::class)
        ->assertSee('Chưa nối Telegram')
        ->callAction('connect')
        ->assertActionMounted('telegramLink');

    $url = (string) $component->get('mountedActions')[0]['arguments']['url'];

    expect($url)->toStartWith('https://t.me/btg_test_bot?start=');

    $code = substr($url, strlen('https://t.me/btg_test_bot?start='));

    expect(app(TelegramLinkCodes::class)->consume($code))->toBe($this->shop->getKey());
});

it('gửi tin thử ngay và ngắt được', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    $this->shop->forceFill(['telegram_chat_id' => '5550001'])->save();

    Livewire::test(TelegramNotifications::class)
        ->assertSee('Đã nối Telegram')
        ->callAction('sendTest')
        ->assertNotified('Đã gửi, xem trong Telegram.')
        ->callAction('disconnect')
        ->assertNotified('Đã ngắt Telegram.');

    Http::assertSent(fn (Request $request): bool => $request['chat_id'] === '5550001');
    expect($this->shop->fresh()->telegram_chat_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'telegram.disconnected')->sole()->user_id)->toBe($this->owner->getKey());
});

it('báo lỗi gửi thử mà không lộ token', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400)]);
    $this->shop->forceFill(['telegram_chat_id' => '5550001'])->save();

    Livewire::test(TelegramNotifications::class)
        ->callAction('sendTest')
        ->assertNotified('Gửi không được')
        ->assertDontSee('token-test');
});

it('chưa cấu hình bot thì chỉ hiện hướng dẫn, không có nút nối', function (): void {
    config(['services.telegram.bot_token' => null]);

    Livewire::test(TelegramNotifications::class)
        ->assertSee('Bot Telegram chưa được cấu hình')
        ->assertActionHidden('connect');
});
