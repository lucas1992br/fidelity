<?php

namespace App\Models;

use Database\Factories\CardStatusLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable log of a card's status changes; no user means the system did it.
 *
 * @property int $id
 * @property int $card_id
 * @property int|null $from_card_status_id
 * @property int $to_card_status_id
 * @property string|null $reason
 * @property int|null $user_id
 * @property Carbon|null $created_at
 */
class CardStatusLog extends Model
{
    /** @use HasFactory<CardStatusLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'card_id',
        'from_card_status_id',
        'to_card_status_id',
        'reason',
        'user_id',
    ];

    /**
     * @return BelongsTo<Card, $this>
     */
    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /**
     * @return BelongsTo<CardStatus, $this>
     */
    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(CardStatus::class, 'from_card_status_id');
    }

    /**
     * @return BelongsTo<CardStatus, $this>
     */
    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(CardStatus::class, 'to_card_status_id');
    }

    /**
     * User who changed the status; null when done by the system.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
