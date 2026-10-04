<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_active
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string $role_label
 */
#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Pt-BR labels of the profiles, keyed by role name.
     *
     * @var array<string, string>
     */
    public const array ROLE_LABELS = [
        'admin' => 'Admin',
        'financeiro' => 'Financeiro',
        'atendente' => 'Atendente',
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

    /**
     * Only users allowed to log in.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Pt-BR label of the user's profile, e.g. "Financeiro".
     *
     * @return Attribute<string, never>
     */
    protected function roleLabel(): Attribute
    {
        return Attribute::get(function (): string {
            $role = $this->getRoleNames()->first();

            return self::ROLE_LABELS[$role] ?? (string) $role;
        });
    }

    /**
     * @return HasMany<CustomerNote, $this>
     */
    public function customerNotes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    /**
     * @return HasMany<PlanChange, $this>
     */
    public function planChanges(): HasMany
    {
        return $this->hasMany(PlanChange::class);
    }

    /**
     * @return HasMany<CardBatch, $this>
     */
    public function cardBatches(): HasMany
    {
        return $this->hasMany(CardBatch::class);
    }

    /**
     * @return HasMany<CardStatusLog, $this>
     */
    public function cardStatusLogs(): HasMany
    {
        return $this->hasMany(CardStatusLog::class);
    }

    /**
     * Payments registered by the user.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Payments refunded by the user.
     *
     * @return HasMany<Payment, $this>
     */
    public function refundedPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'refunded_by_user_id');
    }

    /**
     * @return HasMany<FinancialEntry, $this>
     */
    public function financialEntries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
