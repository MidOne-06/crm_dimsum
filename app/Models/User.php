<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'avatar_path',
        'password',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
            'is_active' => 'boolean',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && ($this->isPanelAdministrator() || $this->roles()->exists());
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function locals(): HasMany
    {
        return $this->hasMany(UserLocal::class);
    }

    /**
     * true si este usuario debe verse limitado a sus locales asignados. Un
     * superadministrador (o el bypass de entorno local/tests) nunca queda
     * restringido, aunque tenga locales asignados -- así se le puede asignar
     * un local "de referencia" sin perder acceso al resto.
     */
    public function isRestrictedToLocals(): bool
    {
        if ($this->isPanelAdministrator() || $this->roles()->where('slug', 'superadministrador')->exists()) {
            return false;
        }

        return $this->locals()->exists();
    }

    /** @return array<int, string> vacío = sin restricción (ve todos los locales) */
    public function assignedLocalIds(): array
    {
        if (! $this->isRestrictedToLocals()) {
            return [];
        }

        return $this->locals()->pluck('local_id')->all();
    }

    public function getFilamentAvatarUrl(): ?string
    {
        return filled($this->avatar_path)
            ? Storage::disk('public')->url($this->avatar_path)
            : null;
    }

    protected static function booted(): void
    {
        static::updating(function (self $user): void {
            if (! $user->isDirty('avatar_path') || blank($user->getOriginal('avatar_path'))) {
                return;
            }

            Storage::disk('public')->delete($user->getOriginal('avatar_path'));
        });

        static::deleting(function (self $user): void {
            if (filled($user->avatar_path)) {
                Storage::disk('public')->delete($user->avatar_path);
            }
        });
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isPanelAdministrator() || $this->roles()->where('slug', 'superadministrador')->exists()) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', fn ($query) => $query->where('slug', $permission))
            ->exists();
    }

    public function isPanelAdministrator(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        $allowedEmails = array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) env('FILAMENT_ADMIN_EMAILS', '')),
        ));

        return in_array(strtolower($this->email), $allowedEmails, true);
    }
}
