<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['subcounty_id', 'institution_id', 'name', 'email', 'phone', 'avatar_path', 'password', 'role', 'status', 'last_login_at', 'must_change_password', 'temporary_password_expires_at', 'mfa_required'])]
#[Hidden(['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $tenantContext = app(TenantContext::class);

            if ($tenantContext->resolved()) {
                $tenantContext->scopeUsers($query);
            }
        });
    }

    public function subcounty(): BelongsTo
    {
        return $this->belongsTo(Subcounty::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** Where an urgent safeguarding SMS reaches this officer. */
    public function routeNotificationForSms(): ?string
    {
        return $this->phone;
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function scopeVisibleTo(Builder $query, User $principal): Builder
    {
        return app(TenantContext::class)->scopeUsers($query, $principal);
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);
        $principal = request()->user();

        return $principal instanceof self
            ? app(TenantContext::class)->scopeUsers($query, $principal)
            : $query->whereRaw('1 = 0');
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
            'last_login_at' => 'datetime',
            'temporary_password_expires_at' => 'datetime',
            'must_change_password' => 'boolean',
            'mfa_required' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_enabled_at' => 'datetime',
            'mfa_recovery_codes' => 'encrypted:array',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }
}
