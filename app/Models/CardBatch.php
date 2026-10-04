<?php

namespace App\Models;

use Database\Factories\CardBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Batch of plastic cards received from the printer into stock.
 *
 * @property int $id
 * @property string $description
 * @property string|null $supplier
 * @property Carbon $received_at
 * @property int $quantity
 * @property string|null $first_number
 * @property string|null $last_number
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CardBatch extends Model
{
    /** @use HasFactory<CardBatchFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'description',
        'supplier',
        'received_at',
        'quantity',
        'first_number',
        'last_number',
        'user_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'date',
            'quantity' => 'integer',
        ];
    }

    /**
     * User who registered the batch.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Card, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }
}
