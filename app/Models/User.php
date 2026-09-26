<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_last_code'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, LogsActivity, Notifiable;

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
            // Encrypted at rest, under the same rule PLAN.md section 3 applies
            // to bank details and government numbers: a TOTP secret in
            // plaintext is a second factor anybody with a dump holds too.
            'two_factor_secret' => 'encrypted',
            'two_factor_last_code' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Who may open the admin panel.
     *
     * Filament grants access implicitly in `local` and refuses it everywhere
     * else unless the model says otherwise, so this has to be explicit before
     * anything is deployed - staging and production would 403 every user.
     *
     * Every authenticated user may open the panel, by design: PLAN.md section 2
     * picks self-hosted auth precisely so there is no per-seat cost and "every
     * foreman and timekeeper is a user". Opening the panel is not access to the
     * data - that is per-resource authorisation and the per-project row-level
     * policies in section 3, which arrive with Phase 6.
     */
    /**
     * The projects this account is assigned to (P6-01). Written only through
     * ProjectAccessService.
     *
     * @return HasMany<ProjectAssignment, $this>
     */
    public function projectAssignments(): HasMany
    {
        return $this->hasMany(ProjectAssignment::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Audit trail settings.
     *
     * PLAN.md §3 treats `activity_log` as append-only — this system produces an
     * audited P&L, so who changed what and when is not optional. Only meaningful
     * attributes are logged, and unchanged ones are skipped so the trail stays
     * readable rather than drowning in no-op updates.
     *
     * `password` is deliberately absent: the hash must never reach the log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
