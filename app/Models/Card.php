<?php

namespace App\Models;

use App\Enums\CardStatus as CardStatusEnum;
use Database\Factories\CardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Plastic card: in stock while unassigned, then linked to a customer.
 *
 * @property int $id
 * @property int|null $card_batch_id
 * @property string $number
 * @property int|null $customer_id
 * @property int $card_status_id
 * @property bool $is_current
 * @property Carbon|null $assigned_at
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Card extends Model
{
    /** @use HasFactory<CardFactory> */
    use HasFactory, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'card_batch_id',
        'number',
        'customer_id',
        'card_status_id',
        'is_current',
        'assigned_at',
        'status_changed_at',
    ];

    /**
     * Audit changes to the fillable attributes in the "cards" log; the causer
     * is the authenticated user (none when run by the scheduled routines).
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('cards')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'assigned_at' => 'date',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CardBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CardBatch::class, 'card_batch_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<CardStatus, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(CardStatus::class, 'card_status_id');
    }

    /**
     * Status history, oldest first.
     *
     * @return HasMany<CardStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(CardStatusLog::class)->oldest()->oldest('id');
    }

    /**
     * The most recent status change; for a card no longer in force it holds
     * the final status, its reason and date.
     *
     * @return HasOne<CardStatusLog, $this>
     */
    public function latestStatusLog(): HasOne
    {
        return $this->hasOne(CardStatusLog::class)->ofMany(['created_at' => 'max', 'id' => 'max']);
    }

    /**
     * Cards available in stock to be linked.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInStock(Builder $query): void
    {
        $query->withStatus(CardStatusEnum::InStock);
    }

    /**
     * Cards in force for their customer (active or suspended).
     *
     * @param  Builder<self>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where('is_current', true);
    }

    /**
     * Cards in any of the given statuses.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithStatus(Builder $query, CardStatusEnum ...$statuses): void
    {
        $query->whereIn('card_status_id', array_map(fn (CardStatusEnum $status): int => $status->id(), $statuses));
    }

    public function hasStatus(CardStatusEnum $status): bool
    {
        return $this->card_status_id === $status->id();
    }
}
