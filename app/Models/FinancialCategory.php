<?php

namespace App\Models;

use App\Enums\EntryType as EntryTypeEnum;
use Database\Factories\FinancialCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $entry_type_id
 * @property string $name
 * @property bool $is_system
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FinancialCategory extends Model
{
    /** @use HasFactory<FinancialCategoryFactory> */
    use HasFactory;

    /**
     * Name of the system category that receives the monthly fee payments.
     */
    public const string MONTHLY_FEES = 'Mensalidades';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entry_type_id',
        'name',
        'is_system',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The system income category used by automatic payment entries.
     */
    public static function monthlyFees(): self
    {
        return self::query()
            ->where('is_system', true)
            ->where('name', self::MONTHLY_FEES)
            ->where('entry_type_id', EntryTypeEnum::Income->id())
            ->sole();
    }

    /**
     * @return BelongsTo<EntryType, $this>
     */
    public function entryType(): BelongsTo
    {
        return $this->belongsTo(EntryType::class);
    }

    /**
     * @return HasMany<FinancialEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class);
    }

    /**
     * Only categories available for new entries.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Only categories of the given entry type.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOfType(Builder $query, EntryTypeEnum $type): void
    {
        $query->where('entry_type_id', $type->id());
    }

    /**
     * Categories offered in the entry form: the active ones of the type, plus
     * the current category of the entry being edited (even if inactive).
     *
     * @return Collection<int, string> Names keyed by id.
     */
    public static function optionsFor(EntryTypeEnum $type, ?int $currentId = null): Collection
    {
        return self::query()
            ->ofType($type)
            ->where(fn (Builder $query) => $query->where('is_active', true)->when($currentId, fn (Builder $query) => $query->orWhere('id', $currentId)))
            ->orderBy('name')
            ->pluck('name', 'id');
    }
}
