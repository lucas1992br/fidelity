<?php

namespace App\Models;

use App\Enums\CardStatus as CardStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Lookup row mirrored by {@see CardStatusEnum}; rows are resolved by slug, never by id.
 *
 * @property int $id
 * @property string $name
 * @property CardStatusEnum $slug
 * @property string $color
 * @property bool $is_current
 * @property bool $is_reusable
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CardStatus extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slug' => CardStatusEnum::class,
            'is_current' => 'boolean',
            'is_reusable' => 'boolean',
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
     * Cards currently in this status.
     *
     * @return HasMany<Card, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class, 'card_status_id');
    }
}
