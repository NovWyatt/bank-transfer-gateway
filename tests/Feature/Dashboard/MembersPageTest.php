<?php

use App\Enums\ShopRole;
use App\Filament\Members\PendingInvitations;
use App\Filament\Pages\Members;
use App\Models\Membership;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;
use App\Tenancy\CurrentShop;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->owner = User::factory()->create(['name' => 'Chủ Tiệm']);
    $this->staff = User::factory()->create(['name' => 'Bạn Nhân Viên']);
    $this->shop = Shop::factory()
        ->withMember($this->owner)
        ->withMember($this->staff, ShopRole::Staff)
        ->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel('app');
    Filament::setTenant($this->shop);
    app(CurrentShop::class)->set($this->shop);
});

function memberRow(Shop $shop, User $user): Membership
{
    return Membership::query()->where('shop_id', $shop->getKey())->where('user_id', $user->getKey())->sole();
}

it('chỉ owner vào được trang thành viên', function (): void {
    $this->get("/app/{$this->shop->slug}/members")
        ->assertOk()
        ->assertSee('Bạn Nhân Viên');

    $this->actingAs($this->staff)
        ->get("/app/{$this->shop->slug}/members")
        ->assertForbidden();
});

it('không hiện thành viên của shop khác', function (): void {
    $stranger = User::factory()->create(['name' => 'Người Shop Khác']);
    Shop::factory()->withMember($stranger)->create();

    $this->get("/app/{$this->shop->slug}/members")->assertDontSee('Người Shop Khác');
});

it('mời thành viên rồi mở ngay hộp có link để chép', function (): void {
    Livewire::test(Members::class)
        ->callAction('invite', data: ['email' => 'lan@example.test', 'role' => ShopRole::Staff->value])
        ->assertHasNoFormErrors()
        ->assertActionMounted('invitationLink')
        ->assertMountedActionModalSee('lan@example.test');

    $invitation = ShopInvitation::query()->sole();
    expect($invitation->shop_id)->toBe($this->shop->getKey())
        ->and($invitation->invited_by)->toBe($this->owner->getKey());
});

it('báo lỗi khi mời người đã là thành viên', function (): void {
    Livewire::test(Members::class)
        ->callAction('invite', data: ['email' => $this->staff->email, 'role' => ShopRole::Staff->value])
        ->assertNotified($this->staff->email.' đã là thành viên của shop.');

    expect(ShopInvitation::query()->count())->toBe(0);
});

it('đổi vai trò và xóa thành viên khác', function (): void {
    $row = memberRow($this->shop, $this->staff);

    Livewire::test(Members::class)
        ->callAction(TestAction::make('changeRole')->table($row), data: ['role' => ShopRole::Owner->value])
        ->assertHasNoFormErrors();

    expect($this->staff->roleIn($this->shop))->toBe(ShopRole::Owner);

    Livewire::test(Members::class)
        ->callAction(TestAction::make('remove')->table($row));

    expect($this->staff->roleIn($this->shop))->toBeNull();
});

it('ẩn nút đổi vai trò và xóa ở dòng của chính mình', function (): void {
    $own = memberRow($this->shop, $this->owner);

    Livewire::test(Members::class)
        ->assertActionHidden(TestAction::make('changeRole')->table($own))
        ->assertActionHidden(TestAction::make('remove')->table($own));
});

it('không với tới thành viên của shop khác qua id', function (): void {
    $stranger = User::factory()->create();
    $otherShop = Shop::factory()->withMember($stranger, ShopRole::Staff)->withMember(User::factory()->create())->create();
    $foreign = memberRow($otherShop, $stranger);

    try {
        Livewire::test(Members::class)->callAction(TestAction::make('remove')->table($foreign));
    } catch (Throwable) {
        // Filament không tìm thấy bản ghi trong truy vấn của bảng: đó chính là điều cần.
    }

    expect($stranger->roleIn($otherShop))->toBe(ShopRole::Staff);
});

it('tạo link mới và thu hồi lời mời ở bảng lời mời', function (): void {
    $invitation = ShopInvitation::factory()->for($this->shop)->create(['email' => 'lan@example.test']);
    $oldHash = $invitation->token_hash;

    Livewire::test(PendingInvitations::class)
        ->assertCanSeeTableRecords([$invitation])
        ->callAction(TestAction::make('renew')->table($invitation))
        ->assertActionMounted('invitationLink');

    expect($invitation->refresh()->token_hash)->not->toBe($oldHash);

    Livewire::test(PendingInvitations::class)
        ->callAction(TestAction::make('revoke')->table($invitation));

    expect(ShopInvitation::query()->count())->toBe(0);
});

it('bảng lời mời không hiện lời mời của shop khác', function (): void {
    $mine = ShopInvitation::factory()->for($this->shop)->create();
    $foreign = ShopInvitation::factory()->for(Shop::factory())->create();

    Livewire::test(PendingInvitations::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);
});
