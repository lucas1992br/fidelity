<?php

namespace App\Models;

use App\Enums\EntryStatus as EntryStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lookup row mirrored by {@see EntryStatusEnum}; rows are resolved by slug, never by id.
 *
 * @property int $id
 * @property string $name
 * @property EntryStatusEnum $slug
 * @property string $color
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EntryStatus extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slug' => EntryStatusEnum::class,
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
     * Financial entries currently in this status.
     *
     * @return HasMany<FinancialEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class, 'entry_status_id');
    }
}
