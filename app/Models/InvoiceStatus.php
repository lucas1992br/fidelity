<?php

namespace App\Models;

use App\Enums\InvoiceStatus as InvoiceStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lookup row mirrored by {@see InvoiceStatusEnum}; rows are resolved by slug, never by id.
 *
 * @property int $id
 * @property string $name
 * @property InvoiceStatusEnum $slug
 * @property string $color
 * @property bool $is_payable
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InvoiceStatus extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slug' => InvoiceStatusEnum::class,
            'is_payable' => 'boolean',
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
     * Invoices currently in this status.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'invoice_status_id');
    }
}
