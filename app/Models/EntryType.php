<?php

namespace App\Models;

use App\Enums\EntryType as EntryTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lookup row mirrored by {@see EntryTypeEnum}; rows are resolved by slug, never by id.
 *
 * @property int $id
 * @property string $name
 * @property EntryTypeEnum $slug
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EntryType extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slug' => EntryTypeEnum::class,
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
     * Financial categories of this type.
     *
     * @return HasMany<FinancialCategory, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(FinancialCategory::class, 'entry_type_id');
    }

    /**
     * Financial entries of this type.
     *
     * @return HasMany<FinancialEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class, 'entry_type_id');
    }
}
