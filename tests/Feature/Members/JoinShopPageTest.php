<?php

use App\Enums\ShopRole;
use App\Filament\Auth\JoinShop;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| Trang mở từ link mời, ngoài vùng cần đăng nhập. Năm trường hợp: link hỏng, khách chưa có tài khoản,
| khách đã có tài khoản, đăng nhập đúng email, đăng nhập email khác.
*/

beforeEach(function (): void {
    Filament::setCurrentPanel('app');

    $this->shop = Shop::factory()->create(['name' => 'Tiệm gốm Nhà Mây']);
    $this->token = Str::random(48);
    $this->invitation = ShopInvitation::factory()->for($this->shop)->withToken($this->token)->create([
        'email' => 'lan@example.test',
        'role' => ShopRole::Staff,
    ]);
    $this->url = "/app/invitations/{$this->token}";
});

it('báo link không dùng được khi token sai, hết hạn hoặc đã nhận', function (string $case): void {
    $url = match ($case) {
        'token sai' => '/app/invitations/'.Str::random(48),
        'hết hạn' => tap($this->url, fn () => $this->invitation->update(['expires_at' => now()->subMinute()])),
        'đã nhận' => tap($this->url, fn () => $this->invitation->update(['accepted_at' => now()])),
    };

    $this->get($url)
        ->assertOk()
        ->assertSee('Lời mời không còn dùng được')
        ->assertDontSee('Tiệm gốm Nhà Mây');
})->with(['token sai', 'hết hạn', 'đã nhận']);

it('trả 404 với token sai định dạng', function (): void {
    $this->get('/app/invitations/ngan-qua')->assertNotFound();
});

it('cho khách chưa có tài khoản tạo tài khoản và vào thẳng shop', function (): void {
    $this->get($this->url)->assertOk()->assertSee('Tham gia Tiệm gốm Nhà Mây')->assertSee('lan@example.test');

    Livewire::test(JoinShop::class, ['token' => $this->token])
        ->fillForm([
            'name' => 'Nguyễn Thị Lan',
            'password' => 'gom-men-lam-2026',
            'passwordConfirmation' => 'gom-men-lam-2026',
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect("/app/{$this->shop->slug}");

    $lan = User::query()->where('email', 'lan@example.test')->sole();

    expect($lan->name)->toBe('Nguyễn Thị Lan')
        ->and($lan->roleIn($this->shop))->toBe(ShopRole::Staff)
        ->and(Filament::auth()->id())->toBe($lan->getKey())
        ->and($this->invitation->refresh()->accepted_by)->toBe($lan->getKey());
});

it('bắt mật khẩu khớp nhau và đủ dài', function (): void {
    Livewire::test(JoinShop::class, ['token' => $this->token])
        ->fillForm([
            'name' => 'Lan',
            'password' => 'ngan',
            'passwordConfirmation' => 'khac',
        ])
        ->call('register')
        ->assertHasFormErrors(['password']);

    expect(User::query()->where('email', 'lan@example.test')->exists())->toBeFalse();
});

it('khách đã có tài khoản thì được đưa đi đăng nhập rồi quay lại link', function (): void {
    User::factory()->create(['email' => 'lan@example.test']);

    $this->get($this->url)
        ->assertOk()
        ->assertSee('Đăng nhập để nhận lời mời')
        ->assertSessionHas('url.intended', url($this->url));

    Livewire::test(JoinShop::class, ['token' => $this->token])
        ->call('register');

    expect(User::query()->where('email', 'lan@example.test')->count())->toBe(1);
});

it('user đăng nhập đúng email bấm tham gia là vào shop', function (): void {
    $lan = User::factory()->create(['email' => 'lan@example.test']);
    $this->actingAs($lan);

    Livewire::test(JoinShop::class, ['token' => $this->token])
        ->assertActionVisible('join')
        ->callAction('join')
        ->assertRedirect("/app/{$this->shop->slug}");

    expect($lan->roleIn($this->shop))->toBe(ShopRole::Staff);

    // Mở lại link đã dùng: báo hết hiệu lực, có đường về dashboard thay vì bỏ người dùng ở đó.
    Livewire::test(JoinShop::class, ['token' => $this->token])
        ->assertSee('Lời mời không còn dùng được')
        ->assertActionVisible('home')
        ->assertActionHidden('join');
});

it('user đăng nhập email khác không nhận được lời mời', function (): void {
    $minh = User::factory()->create(['email' => 'minh@example.test']);
    $this->actingAs($minh);

    $this->get($this->url)->assertOk()->assertSee('Lời mời gửi tới tài khoản khác');

    Livewire::test(JoinShop::class, ['token' => $this->token])
        ->assertActionHidden('join')
        ->assertActionVisible('logout')
        ->call('register');

    expect($minh->roleIn($this->shop))->toBeNull()
        ->and($this->invitation->refresh()->accepted_at)->toBeNull();
});
