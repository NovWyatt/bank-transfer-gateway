<?php

use App\Actions\Members\AcceptInvitation;
use App\Actions\Members\ChangeMemberRole;
use App\Actions\Members\InviteMember;
use App\Actions\Members\RemoveMember;
use App\Actions\Members\RevokeInvitation;
use App\Enums\ShopRole;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;

beforeEach(function (): void {
    $this->owner = User::factory()->create();
    $this->shop = Shop::factory()->withMember($this->owner)->create();
});

/** Dòng shop_user của user trong shop. */
function membershipOf(Shop $shop, User $user): Membership
{
    return Membership::query()->where('shop_id', $shop->getKey())->where('user_id', $user->getKey())->sole();
}

describe('mời', function (): void {
    it('lưu sha256 của token, không lưu token gốc', function (): void {
        $issued = (new InviteMember)->handle($this->shop, '  Lan.Nguyen@Example.TEST ', ShopRole::Staff, $this->owner);

        $invitation = ShopInvitation::withoutGlobalScopes()->sole();

        expect($invitation->email)->toBe('lan.nguyen@example.test')
            ->and($invitation->token_hash)->toBe(hash('sha256', $issued->token))
            ->and($invitation->token_hash)->not->toContain($issued->token)
            ->and(strlen($issued->token))->toBe(InviteMember::TOKEN_LENGTH)
            ->and($issued->url())->toEndWith("/app/invitations/{$issued->token}")
            ->and($invitation->expires_at->diffInDays(now(), true))->toBeGreaterThan(6.9);

        expect(AuditLog::withoutGlobalScopes()->sole()->action)->toBe('member.invited');
    });

    it('mời lại cùng email thì làm mới lời mời cũ, link cũ hết dùng', function (): void {
        $first = (new InviteMember)->handle($this->shop, 'lan@example.test', ShopRole::Staff, $this->owner);
        $second = (new InviteMember)->handle($this->shop, 'LAN@example.test', ShopRole::Owner, $this->owner);

        $invitation = ShopInvitation::withoutGlobalScopes()->sole();

        expect($invitation->role)->toBe(ShopRole::Owner)
            ->and($invitation->token_hash)->toBe(hash('sha256', $second->token))
            ->and($invitation->token_hash)->not->toBe(hash('sha256', $first->token));

        expect(AuditLog::withoutGlobalScopes()->pluck('action')->all())
            ->toBe(['member.invited', 'member.invitation_renewed']);
    });

    it('không mời người đã là thành viên', function (): void {
        expect(fn () => (new InviteMember)->handle($this->shop, strtoupper($this->owner->email), ShopRole::Staff, $this->owner))
            ->toThrow(DomainException::class);
    });
});

describe('nhận lời mời', function (): void {
    it('thêm user vào shop với vai trò trong lời mời, mỗi lời mời dùng một lần', function (): void {
        $issued = (new InviteMember)->handle($this->shop, 'lan@example.test', ShopRole::Staff, $this->owner);
        $lan = User::factory()->create(['email' => 'Lan@Example.test']);

        $shop = (new AcceptInvitation)->handle($issued->invitation, $lan, '198.51.100.4');

        expect($shop->is($this->shop))->toBeTrue()
            ->and($lan->roleIn($this->shop))->toBe(ShopRole::Staff)
            ->and($issued->invitation->refresh()->accepted_by)->toBe($lan->getKey());

        expect(fn () => (new AcceptInvitation)->handle($issued->invitation, $lan))
            ->toThrow(DomainException::class, 'đã được dùng');
    });

    it('không nhận lời mời đã hết hạn', function (): void {
        $invitation = ShopInvitation::factory()->for($this->shop)->expired()->create(['email' => 'lan@example.test']);
        $lan = User::factory()->create(['email' => 'lan@example.test']);

        expect(fn () => (new AcceptInvitation)->handle($invitation, $lan))->toThrow(DomainException::class);
        expect($lan->roleIn($this->shop))->toBeNull();
    });

    it('không nhận lời mời gửi tới email khác', function (): void {
        $invitation = ShopInvitation::factory()->for($this->shop)->create(['email' => 'lan@example.test']);
        $other = User::factory()->create(['email' => 'minh@example.test']);

        expect(fn () => (new AcceptInvitation)->handle($invitation, $other))->toThrow(DomainException::class, 'lan@example.test');
        expect($other->roleIn($this->shop))->toBeNull();
    });
});

describe('đổi vai trò và xóa', function (): void {
    it('không hạ quyền owner cuối cùng', function (): void {
        expect(fn () => (new ChangeMemberRole)->handle(membershipOf($this->shop, $this->owner), ShopRole::Staff, $this->owner))
            ->toThrow(DomainException::class, 'ít nhất một chủ shop');

        expect($this->owner->roleIn($this->shop))->toBe(ShopRole::Owner);
    });

    it('hạ quyền được khi còn owner khác, có ghi nhật ký', function (): void {
        $second = User::factory()->create();
        $this->shop->members()->attach($second, ['role' => ShopRole::Owner->value]);

        (new ChangeMemberRole)->handle(membershipOf($this->shop, $second), ShopRole::Staff, $this->owner);

        expect($second->roleIn($this->shop))->toBe(ShopRole::Staff);

        $log = AuditLog::withoutGlobalScopes()->sole();
        expect($log->action)->toBe('member.role_changed')
            ->and($log->subject_id)->toBe($second->getKey())
            ->and($log->meta)->toEqual(['from' => 'owner', 'to' => 'staff']);
    });

    it('không xóa owner cuối cùng nhưng xóa được nhân viên', function (): void {
        expect(fn () => (new RemoveMember)->handle(membershipOf($this->shop, $this->owner), $this->owner))
            ->toThrow(DomainException::class);

        $staff = User::factory()->create();
        $this->shop->members()->attach($staff, ['role' => ShopRole::Staff->value]);

        (new RemoveMember)->handle(membershipOf($this->shop, $staff), $this->owner);

        expect($staff->roleIn($this->shop))->toBeNull()
            ->and(User::query()->whereKey($staff->getKey())->exists())->toBeTrue();
    });
});

describe('thu hồi', function (): void {
    it('xóa lời mời chưa nhận và ghi nhật ký', function (): void {
        $invitation = ShopInvitation::factory()->for($this->shop)->create(['email' => 'lan@example.test']);

        (new RevokeInvitation)->handle($invitation, $this->owner);

        expect(ShopInvitation::withoutGlobalScopes()->count())->toBe(0)
            ->and(AuditLog::withoutGlobalScopes()->sole()->meta)->toEqual(['email' => 'lan@example.test', 'role' => 'staff']);
    });

    it('không thu hồi lời mời đã được nhận', function (): void {
        $invitation = ShopInvitation::factory()->for($this->shop)->create(['accepted_at' => now()]);

        expect(fn () => (new RevokeInvitation)->handle($invitation, $this->owner))->toThrow(DomainException::class);
    });
});
