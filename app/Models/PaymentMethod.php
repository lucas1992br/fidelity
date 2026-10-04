<?php

namespace App\Models;

use App\Enums\PaymentMethod as PaymentMethodEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lookup row mirrored by {@see PaymentMethodEnum}; rows are resolved by slug, never by id.
 *
 * @property int $id
 * @property string $name
 * @property PaymentMethodEnum $slug
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaymentMethod extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slug' => PaymentMethodEnum::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Only rows available for selection.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Payments made with this method.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payment_method_id');
    }

    /**
     * Financial entries settled with this method.
     *
     * @return HasMany<FinancialEntry, $this>
     */
    public function financialEntries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class, 'payment_method_id');
    }
}
