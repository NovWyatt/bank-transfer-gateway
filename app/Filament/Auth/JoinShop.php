<?php

namespace App\Filament\Auth;

use App\Actions\Members\AcceptInvitation;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;
use App\Tenancy\ShopScope;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use DomainException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;

/**
 * Trang mở từ link mời: /app/invitations/{token}. Nằm ngoài vùng cần đăng nhập.
 *
 * Năm trường hợp: link hỏng hoặc hết hạn; khách chưa có tài khoản (tạo tài khoản ngay tại đây);
 * khách đã có tài khoản (đăng nhập rồi quay lại); đang đăng nhập đúng email (bấm tham gia);
 * đang đăng nhập email khác (đăng xuất).
 *
 * @property-read Schema $form
 */
class JoinShop extends SimplePage
{
    use WithRateLimiting;

    #[Locked]
    public string $token = '';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Lời mời đã đọc trong request này. Thuộc tính private không được Livewire giữ giữa các request,
     * nên mỗi lần bấm đều đọc lại và thấy lời mời vừa bị thu hồi hay vừa được dùng.
     */
    private ?ShopInvitation $loadedInvitation = null;

    private bool $invitationLoaded = false;

    public function mount(string $token): void
    {
        $this->token = $token;

        // Đăng nhập xong, Filament đưa người dùng quay lại đúng link này.
        if ($this->state() === 'login') {
            redirect()->setIntendedUrl(request()->fullUrl());
        }

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('email')
                    ->label('Email')
                    ->default(fn (): ?string => $this->invitation()?->email)
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('name')
                    ->label('Tên của bạn')
                    ->required()
                    ->maxLength(255)
                    ->autofocus(),
                TextInput::make('password')
                    ->label('Mật khẩu')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::default())
                    ->same('passwordConfirmation')
                    ->autocomplete('new-password'),
                TextInput::make('passwordConfirmation')
                    ->label('Nhập lại mật khẩu')
                    ->password()
                    ->revealable()
                    ->required()
                    ->dehydrated(false)
                    ->autocomplete('new-password'),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('register')
                    ->footer([
                        Actions::make([
                            Action::make('register')
                                ->label('Tạo tài khoản và tham gia')
                                ->submit('register'),
                        ])->fullWidth(),
                    ])
                    ->visible(fn (): bool => $this->state() === 'register'),
                Actions::make([
                    $this->joinAction(),
                    $this->loginAction(),
                    $this->logoutAction(),
                    $this->homeAction(),
                ])->fullWidth(),
            ]);
    }

    public function joinAction(): Action
    {
        return Action::make('join')
            ->label('Tham gia shop')
            ->visible(fn (): bool => $this->state() === 'join')
            ->action(function (AcceptInvitation $accept): void {
                /** @var User $user */
                $user = Filament::auth()->user();

                $this->accept($accept, $user);
            });
    }

    public function loginAction(): Action
    {
        return Action::make('login')
            ->label('Đăng nhập để nhận lời mời')
            ->visible(fn (): bool => $this->state() === 'login')
            ->url(fn (): string => Filament::getLoginUrl() ?? '/');
    }

    public function logoutAction(): Action
    {
        return Action::make('logout')
            ->label('Đăng xuất')
            ->color('gray')
            ->visible(fn (): bool => $this->state() === 'mismatch')
            ->action(function (): void {
                Filament::auth()->logout();
                session()->invalidate();
                session()->regenerateToken();

                $this->redirectToSelf();
            });
    }

    /** Link hỏng mà người xem đang đăng nhập (thường là vừa nhận xong rồi mở lại): cho đường về dashboard. */
    public function homeAction(): Action
    {
        return Action::make('home')
            ->label('Về dashboard')
            ->color('gray')
            ->visible(fn (): bool => $this->state() === 'invalid' && Filament::auth()->check())
            ->url(fn (): string => Filament::getUrl() ?? '/');
    }

    /** Khách chưa có tài khoản: tạo tài khoản bằng email trong lời mời rồi nhận lời mời. */
    public function register(AcceptInvitation $accept): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $e) {
            Notification::make()->danger()->title("Thử lại sau {$e->secondsUntilAvailable} giây.")->send();

            return;
        }

        $invitation = $this->invitation();

        if ($this->state() !== 'register' || $invitation === null) {
            return;
        }

        $data = $this->form->getState();

        try {
            // Tạo tài khoản và nhận lời mời cùng một transaction: nhận hỏng thì không để lại tài khoản thừa.
            [$user, $shop] = DB::transaction(/** @return array{User, Shop} */ function () use ($accept, $invitation, $data): array {
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $invitation->email,
                    'password' => $data['password'],
                ]);

                return [$user, $accept->handle($invitation, $user, request()->ip())];
            });
        } catch (DomainException|UniqueConstraintViolationException) {
            // Lời mời vừa bị dùng, hoặc email vừa được đăng ký ở tab khác: tải lại để hiện đúng trường hợp.
            $this->redirectToSelf();

            return;
        }

        Filament::auth()->login($user);
        session()->regenerate();

        $this->redirect(Filament::getUrl($shop));
    }

    public function getTitle(): string|Htmlable
    {
        return 'Lời mời vào shop';
    }

    public function getHeading(): string|Htmlable
    {
        return match ($this->state()) {
            'invalid' => 'Lời mời không còn dùng được',
            'mismatch' => 'Lời mời gửi tới tài khoản khác',
            default => 'Tham gia '.$this->shop()?->name,
        };
    }

    public function getSubheading(): string|Htmlable|null
    {
        $invitation = $this->invitation();

        if ($invitation === null) {
            return 'Link đã được dùng, đã hết hạn hoặc đã bị thu hồi. Nhờ chủ shop gửi link mới.';
        }

        $role = mb_strtolower($invitation->role->label());

        return match ($this->state()) {
            'register' => "Bạn được mời làm {$role}. Đặt tên và mật khẩu để tạo tài khoản.",
            'login' => "Email {$invitation->email} đã có tài khoản. Đăng nhập, rồi bạn sẽ quay lại đây để nhận lời mời.",
            'join' => "Bạn sẽ vào shop với vai trò {$role}.",
            'mismatch' => "Lời mời dành cho {$invitation->email}. Đăng xuất rồi mở lại link này bằng tài khoản đó.",
            default => null,
        };
    }

    /** @return 'invalid'|'register'|'login'|'join'|'mismatch' */
    private function state(): string
    {
        $invitation = $this->invitation();

        if ($invitation === null) {
            return 'invalid';
        }

        $user = Filament::auth()->user();

        if ($user instanceof User) {
            return ShopInvitation::normalizeEmail($user->email) === $invitation->email ? 'join' : 'mismatch';
        }

        return User::query()->where('email', $invitation->email)->exists() ? 'login' : 'register';
    }

    /** Lời mời còn dùng được của link này, hoặc null. */
    private function invitation(): ?ShopInvitation
    {
        if (! $this->invitationLoaded) {
            $invitation = ShopInvitation::withoutGlobalScope(ShopScope::class)
                ->where('token_hash', ShopInvitation::hashToken($this->token))
                ->first();

            $this->loadedInvitation = $invitation?->isUsable() ? $invitation : null;
            $this->invitationLoaded = true;
        }

        return $this->loadedInvitation;
    }

    private function redirectToSelf(): void
    {
        $this->redirect(route('filament.app.invitations.accept', ['token' => $this->token]));
    }

    private function shop(): ?Shop
    {
        $invitation = $this->invitation();

        return $invitation === null ? null : Shop::query()->find($invitation->shop_id);
    }

    private function accept(AcceptInvitation $accept, User $user): void
    {
        $invitation = $this->invitation();

        if ($invitation === null) {
            return;
        }

        try {
            $shop = $accept->handle($invitation, $user, request()->ip());
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->redirect(Filament::getUrl($shop));
    }
}
