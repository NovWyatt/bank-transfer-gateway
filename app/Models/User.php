<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\ShopRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return BelongsToMany<Shop, $this, Membership, 'pivot'> */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class)
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Vai trò trong shop, hoặc null nếu không phải thành viên. */
    public function roleIn(Shop $shop): ?ShopRole
    {
        return $this->shops()->whereKey($shop->getKey())->first()?->pivot->role;
    }

    /** Ai có tài khoản đều vào được panel; shop nào thì do canAccessTenant quyết. */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /** @return Collection<int, Shop> */
    public function getTenants(Panel $panel): Collection
    {
        return $this->shops()->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Shop && $this->shops()->whereKey($tenant->getKey())->exists();
    }
}
