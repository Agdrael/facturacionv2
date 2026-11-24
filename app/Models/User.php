<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Services\Access\AccessControlService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $workos_id
 * @property string|null $remember_token
 * @property string $avatar
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'workos_id', 'avatar'])]
#[Hidden(['workos_id', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** @return list<string> */
    public function permissionCodes(): array
    {
        $codes = Permission::query()->whereHas('roles.users', fn ($query) => $query->whereKey($this->getKey()))
            ->whereIn('code', array_keys(config('permissions.catalog', [])))->orderBy('code')->pluck('code')->all();
        if (! $this->hasRole(['admin', 'contador'])) {
            $codes = array_values(array_diff($codes, ['libreria.ventas.invalidar']));
        }

        return $codes;
    }

    /** @param string|list<string> $codes */
    public function hasRole(string|array $codes): bool
    {
        return $this->roles()->whereIn('code', (array) $codes)->exists();
    }

    public function hasPermission(string $code): bool
    {
        if (! array_key_exists($code, config('permissions.catalog', []))) {
            return false;
        }
        if (in_array($code, ['libreria.ventas.invalidar', 'libreria.recibos.anular', 'libreria.recibos.conciliar'], true) && ! $this->hasRole(['admin', 'contador'])) {
            return false;
        }

        return $this->roles()->whereHas('permissions', fn ($query) => $query->where('code', $code))->exists();
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            app(AccessControlService::class)->protectLastAdmin($this);

            return parent::delete();
        });
    }

    /**
     * Get the user's initials.
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

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
}
